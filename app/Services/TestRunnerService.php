<?php

namespace App\Services;

use App\Models\Test;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Service responsible for staging workflows and monitoring Playwright execution on Gitea Actions.
 * It manages the polling lifecycle, log streaming, and normalization of Gitea API responses.
 */
class TestRunnerService
{
    private const ACTIONS_POLL_INTERVAL_SECONDS = 5;
    private const ACTIONS_MAX_WAIT_SECONDS = 900;

    public function __construct(
        private GiteaService $gitea
    ) {
    }

    /**
     * Injects custom test credentials into the Playwright workflow YAML file and writes it to the target repo.
     *
     * @param string $outputDir The local cloned repository workspace.
     * @param Test $test The active Test model containing credentials.
     */
    public function prepareWorkflow(string $outputDir, Test $test): void
    {
        $sourceWorkflowFile = base_path('playwright/playwright.yml');
        $targetWorkflowDir = $outputDir . '/.gitea/workflows';

        if (File::exists($targetWorkflowDir)) {
            File::deleteDirectory($targetWorkflowDir);
        }
        File::makeDirectory($targetWorkflowDir, 0755, true);

        if (File::exists($sourceWorkflowFile)) {
            $content = File::get($sourceWorkflowFile);

            if (!empty($test->test_email)) {
                $content = str_replace(
                    'TEST_EMAIL: playwright@example.com',
                    'TEST_EMAIL: ' . $test->test_email,
                    $content
                );
            }
            if (!empty($test->test_password)) {
                $content = str_replace(
                    'TEST_PASSWORD: playwright',
                    'TEST_PASSWORD: ' . $test->test_password,
                    $content
                );
            }

            File::put($targetWorkflowDir . '/playwright.yml', $content);
        }
    }

    /**
     * Standardizes varied Gitea API responses for action runs into a unified format.
     * Addresses discrepancies across different versions of the Gitea API payload structure.
     *
     * @param array $payload Raw run payload from Gitea.
     * @return array Standardized array with expected keys (id, status, conclusion, head_sha, html_url).
     */
    public function normalizeActionRunPayload(array $payload): array
    {
        $status = strtolower((string) ($payload['status'] ?? ''));
        $conclusion = strtolower((string) ($payload['conclusion'] ?? ''));

        // Gitea structures the commit SHA differently depending on the endpoint used.
        $headSha = (string) ($payload['head_sha'] ?? '');
        if ($headSha === '') {
            $headSha = (string) ($payload['sha'] ?? '');
        }
        if ($headSha === '' && isset($payload['head_commit']) && is_array($payload['head_commit'])) {
            $headSha = (string) (($payload['head_commit']['id'] ?? null) ?? ($payload['head_commit']['sha'] ?? null) ?? '');
        }
        if ($headSha === '' && isset($payload['commit']) && is_array($payload['commit'])) {
            $headSha = (string) (($payload['commit']['id'] ?? null) ?? ($payload['commit']['sha'] ?? null) ?? '');
        }

        // Handle older Gitea API payload aliases
        if ($status === '' && isset($payload['state'])) {
            $status = strtolower((string) $payload['state']);
        }
        if ($conclusion === '' && isset($payload['result'])) {
            $conclusion = strtolower((string) $payload['result']);
        }

        return [
            'id' => (int) ($payload['id'] ?? 0),
            'status' => $status,
            'conclusion' => $conclusion,
            'head_sha' => strtolower(trim($headSha)),
            'html_url' => (string) ($payload['html_url'] ?? ''),
        ];
    }

    /**
     * Scans recent action runs in the target branch to find the most applicable workflow run.
     * Attempts various query variations to bypass caching or pagination issues in Gitea.
     *
     * @param string $owner
     * @param string $repo
     * @param string $branch
     * @param string|null $headSha Optional specific commit SHA to match against.
     * @return array|null Normalized run array or null if not found.
     */
    public function fetchLatestActionRun(string $owner, string $repo, string $branch, ?string $headSha = null): ?array
    {
        // Gitea sometimes fails to interpret standard 'branch' queries; test alternate parameter combinations.
        $queryVariants = [
            ['branch' => $branch, 'limit' => 20, 'page' => 1],
            ['branch' => $branch, 'per_page' => 20, 'page' => 1],
            ['ref' => $branch, 'limit' => 20, 'page' => 1],
            ['ref' => $branch, 'per_page' => 20, 'page' => 1],
        ];

        foreach ($queryVariants as $query) {
            $json = $this->gitea->getActionRuns($owner, $repo, $query);
            $runs = [];

            // Accommodate Gitea API structure differences (workflow_runs vs runs)
            if (is_array($json['workflow_runs'] ?? null)) {
                $runs = $json['workflow_runs'];
            } elseif (is_array($json['runs'] ?? null)) {
                $runs = $json['runs'];
            } elseif (is_array($json)) {
                $runs = $json;
            }

            if (! is_array($runs) || $runs === []) {
                continue;
            }

            $normalizedRuns = array_values(array_filter(array_map(function ($run) {
                if (! is_array($run)) {
                    return null;
                }
                return $this->normalizeActionRunPayload($run);
            }, $runs)));

            if ($normalizedRuns === []) {
                continue;
            }

            if ($headSha !== null && $headSha !== '') {
                $normalizedHeadSha = strtolower(trim($headSha));
                foreach ($normalizedRuns as $run) {
                    if (($run['head_sha'] ?? '') === $normalizedHeadSha) {
                        return $run;
                    }
                }

                // If a specific SHA is required, do not fall back to older unrelated runs.
                continue;
            }

            return $normalizedRuns[0];
        }

        return null;
    }

    /**
     * Fetches details of an action run explicitly by its ID.
     *
     * @param string $owner
     * @param string $repo
     * @param int $runId
     * @return array|null Normalized run array.
     */
    public function fetchActionRunById(string $owner, string $repo, int $runId): ?array
    {
        if ($runId <= 0) {
            return null;
        }

        $json = $this->gitea->getActionRun($owner, $repo, $runId);
        if (! is_array($json)) {
            return null;
        }

        return $this->normalizeActionRunPayload($json);
    }

    /**
     * Retrieves all jobs (tasks) for a specific run and normalizes their statuses.
     *
     * @param string $owner
     * @param string $repo
     * @param int $runId
     * @return array Array of normalized jobs.
     */
    public function fetchRunJobs(string $owner, string $repo, int $runId): array
    {
        if ($runId <= 0) {
            return [];
        }

        $json = $this->gitea->getRunJobs($owner, $repo, $runId);
        $jobs = [];
        if (is_array($json['jobs'] ?? null)) {
            $jobs = $json['jobs'];
        } elseif (is_array($json['workflow_jobs'] ?? null)) {
            $jobs = $json['workflow_jobs'];
        } elseif (is_array($json)) {
            $jobs = $json;
        }

        if (! is_array($jobs)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($job) {
            if (! is_array($job)) {
                return null;
            }

            return [
                'id' => (int) ($job['id'] ?? 0),
                'name' => (string) (($job['name'] ?? null) ?? ($job['display_title'] ?? null) ?? ''),
                'status' => strtolower((string) ($job['status'] ?? '')),
                'conclusion' => strtolower((string) ($job['conclusion'] ?? '')),
            ];
        }, $jobs)));
    }

    /**
     * Extracts readable text logs from a Gitea job log provided as a binary ZIP stream.
     *
     * @param string $binary Raw ZIP binary data.
     * @return string|null Concatenated text logs, or null if parsing fails.
     */
    public function extractTextFromZip(string $binary): ?string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'gitea-job-log-');
        if ($tmpFile === false) {
            return null;
        }

        try {
            File::put($tmpFile, $binary);
            $zip = new ZipArchive();
            if ($zip->open($tmpFile) !== true) {
                return null;
            }

            $chunks = [];
            // Iterate over all files within the ZIP and concatenate text.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === '' || str_ends_with($name, '/')) {
                    continue; // Skip directories
                }

                $content = $zip->getFromIndex($i);
                if ($content === false || $content === '') {
                    continue;
                }

                $chunks[] = "# {$name}\n" . $content;
            }
            $zip->close();

            if ($chunks === []) {
                return null;
            }

            return implode("\n\n", $chunks);
        } finally {
            // Ensure temporary file is always deleted to prevent disk space leaks
            @unlink($tmpFile);
        }
    }

    /**
     * Retrieves the raw log text for a specific job, handling ZIP decoding if necessary.
     *
     * @param string $owner
     * @param string $repo
     * @param int $jobId
     * @return string|null Uncompressed text log.
     */
    public function fetchJobLogText(string $owner, string $repo, int $jobId): ?string
    {
        if ($jobId <= 0) {
            return null;
        }

        $body = $this->gitea->getJobLog($owner, $repo, $jobId);
        if ($body === '') {
            return null;
        }

        // ZIP files always begin with local file header signature 0x04034B50 ("PK\x03\x04")
        $looksLikeZip = str_starts_with($body, "PK\x03\x04");
        if ($looksLikeZip) {
            $unzipped = $this->extractTextFromZip($body);
            return $unzipped === null ? null : $unzipped;
        }

        return $body;
    }

    /**
     * Streams log lines to a local file by comparing new fetches with a persistent state array.
     * Only appends the difference (delta) to prevent duplication.
     *
     * @param string $logFile Local log file path.
     * @param int $runId Target run ID.
     * @param array $job Job metadata.
     * @param string $fullText The entire current log text fetched from the API.
     * @param array &$jobLogState Persistent state reference tracking what has already been logged.
     * @return bool True if new log lines were appended.
     */
    public function appendDeltaJobLog(string $logFile, int $runId, array $job, string $fullText, array &$jobLogState): bool
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $fullText);
        // Strip ANSI escape sequences to keep the log file clean
        $normalized = preg_replace("/\x1B\[[0-9;]*[A-Za-z]/", '', $normalized) ?? $normalized;
        $normalized = trim($normalized, "\n");

        if ($normalized === '') {
            return false;
        }

        $jobId = (int) ($job['id'] ?? 0);
        $prev = (string) ($jobLogState[$jobId]['full'] ?? '');

        if ($prev === $normalized) {
            return false; // No new logs
        }

        // Append only new segments if the new log strictly appends to the old log
        if ($prev !== '' && str_starts_with($normalized, $prev)) {
            $delta = ltrim(substr($normalized, strlen($prev)), "\n");
            if ($delta === '') {
                return false;
            }

            File::append($logFile, $delta . "\n");
            $jobLogState[$jobId]['full'] = $normalized;

            return true;
        }

        // If the log was rotated or restarted on the remote, reprint entirely.
        File::append($logFile, "\n{$normalized}\n");
        $jobLogState[$jobId]['full'] = $normalized;

        return true;
    }

    /**
     * Orchestrates the fetching and appending of delta logs for all Playwright jobs within a run.
     *
     * @param string $logFile
     * @param string $owner
     * @param string $repo
     * @param int $runId
     * @param array &$jobLogState
     * @return bool True if any jobs produced new log outputs.
     */
    public function streamRunLogsToGeneratorLog(string $logFile, string $owner, string $repo, int $runId, array &$jobLogState): bool
    {
        $jobs = $this->fetchRunJobs($owner, $repo, $runId);
        if ($jobs === []) {
            return false;
        }

        $hasChanges = false;
        foreach ($jobs as $job) {
            $jobName = strtolower((string) ($job['name'] ?? ''));
            // Filter to only capture jobs executing Playwright tasks
            if ($jobName !== '' && ! str_contains($jobName, 'playwright')) {
                continue;
            }

            $jobId = (int) ($job['id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }

            $jobLog = $this->fetchJobLogText($owner, $repo, $jobId);
            if ($jobLog === null) {
                continue;
            }

            $changed = $this->appendDeltaJobLog($logFile, $runId, $job, $jobLog, $jobLogState);
            $hasChanges = $hasChanges || $changed;
        }

        return $hasChanges;
    }

    /**
     * Ensures all trailing logs are successfully pulled before closing the job stream.
     * This compensates for Gitea's asynchronous log aggregation delays.
     *
     * @param string $logFile
     * @param string $owner
     * @param string $repo
     * @param int $runId
     * @param array &$jobLogState
     */
    public function flushFinalRunLogs(string $logFile, string $owner, string $repo, int $runId, array &$jobLogState): void
    {
        $maxAttempts = 10;
        $stablePasses = 0;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $changed = $this->streamRunLogsToGeneratorLog($logFile, $owner, $repo, $runId, $jobLogState);

            // Require two consecutive passes with no changes to confirm the stream is completely stable
            if ($changed) {
                $stablePasses = 0;
            } else {
                $stablePasses++;
            }

            if ($stablePasses >= 2) {
                break;
            }

            sleep(2);
        }
    }

    /**
     * High-level polling method invoked by the PollTestJob background worker.
     * Monitors a Gitea action execution, streams its live logs to the local filesystem,
     * and signals completion status to the parent job scheduler.
     *
     * @param string $logFile File to write the live logs into.
     * @param string $repoFullName Formatted as 'owner/repo'.
     * @param string $testBranch Branch running the Playwright tests.
     * @param string $headSha Ensure we poll the correct run corresponding to our commit.
     * @param Test $test Target Test model.
     * @param int|null &$trackedRunId Maintain persistence on which run ID is being tracked.
     * @return array Polling status array (`status` => 'pending', 'completed', 'error', 'cancelled').
     */
    public function pollPlaywrightActions(
        string $logFile,
        string $repoFullName,
        string $testBranch,
        string $headSha,
        Test $test,
        ?int &$trackedRunId
    ): array {

        [$owner, $repo] = app(TestService::class)->parseRepoFullName($repoFullName);
        if ($owner === null || $repo === null) {
            return ['status' => 'error', 'error' => 'Invalid repository name format. Expected owner/repo.'];
        }

        if (app(TestService::class)->isGenerationCancelled($test)) {
            return ['status' => 'cancelled', 'error' => 'Generation cancelled by user.'];
        }

        $run = null;
        // Prioritize tracking an already established run ID to bypass SHA search overhead.
        if ($trackedRunId !== null) {
            $run = $this->fetchActionRunById($owner, $repo, $trackedRunId);
        }

        if ($run === null) {
            $run = $this->fetchLatestActionRun($owner, $repo, $testBranch, $headSha);
        }

        if ($run === null && $trackedRunId !== null) {
            // Fallback scenario: if the explicitly tracked run disappears but a new one exists for this branch.
            $latestRun = $this->fetchLatestActionRun($owner, $repo, $testBranch);
            if ($latestRun !== null) {
                $latestRunId = (int) ($latestRun['id'] ?? 0);
                if ($latestRunId >= $trackedRunId || $latestRunId === 0) {
                    $run = $latestRun;
                    $trackedRunId = $latestRunId > 0 ? $latestRunId : $trackedRunId;
                }
            }
        }

        if ($run === null) {
            return ['status' => 'pending']; // Run hasn't spawned yet on Gitea Action queue.
        }

        $runId = (int) ($run['id'] ?? 0);
        if ($runId > 0 && $trackedRunId === null) {
            File::append($logFile, "\n[" . now()->format('Y-m-d H:i:s') . "] Starting Playwright tests on Gitea Actions run #{$runId}.\n");
        }
        if ($runId > 0) {
            $trackedRunId = $runId;
        }
        $status = (string) ($run['status'] ?? '');
        $conclusion = (string) ($run['conclusion'] ?? '');

        // GET STATE FROM CACHE
        // jobLogState is persisted cross-poll using Cache to remember what logs have already been extracted.
        $cacheKey = "test_job_log_state_{$test->id}";
        $jobLogState = Cache::get($cacheKey, []);

        $this->streamRunLogsToGeneratorLog($logFile, $owner, $repo, $runId, $jobLogState);

        // SAVE STATE TO CACHE
        Cache::put($cacheKey, $jobLogState, now()->addHours(1));

        // Detect termination
        if (in_array($status, ['completed', 'success'], true)) {
            $this->flushFinalRunLogs($logFile, $owner, $repo, $runId, $jobLogState);
            Cache::forget($cacheKey);

            $completedText = "Gitea Actions run #{$runId} completed";
            if ($conclusion !== '') {
                $completedText .= " ({$conclusion})";
            }
            File::append($logFile, $completedText . ".\n");

            // Evaluate the conclusion state of the action.
            if ($conclusion === '' || in_array($conclusion, ['success', 'neutral', 'skipped'], true)) {
                return ['status' => 'completed', 'run' => $run];
            }

            $runUrl = (string) ($run['html_url'] ?? '');
            $error = "Playwright tests failed on Gitea Actions (conclusion: {$conclusion}).";
            if ($runUrl !== '') {
                $error .= " Run: {$runUrl}";
            }

            return ['status' => 'error', 'error' => $error];
        }

        return ['status' => 'pending'];
    }

}
