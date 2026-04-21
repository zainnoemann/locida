<?php

namespace App\Services;

use App\Models\Test;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class PlaywrightGeneratorService
{
    private const DEFAULT_SOURCE_BRANCH = 'main';
    private const DEFAULT_TEST_BRANCH = 'playwright';
    private const ACTIONS_POLL_INTERVAL_SECONDS = 5;
    private const ACTIONS_MAX_WAIT_SECONDS = 900;
    private const GENERATED_ARTIFACTS = [
        'fixtures',
        'pages',
        'tests',
        'playwright.config.ts',
        'package.json',
        'tsconfig.json',
    ];

    private function normalizeRuntimeHost(string $host): string
    {
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
            return 'host.docker.internal';
        }

        return $host;
    }

    private function resolveGitIdentity(?string $fullName): array
    {
        $username = 'gitea';

        if (! empty($fullName) && str_contains($fullName, '/')) {
            [$owner] = explode('/', $fullName, 2);
            if (! empty($owner)) {
                $username = $owner;
            }
        }

        $emailLocalPart = preg_replace('/[^a-zA-Z0-9._-]/', '', $username);
        if (empty($emailLocalPart)) {
            $emailLocalPart = 'gitea';
        }

        return [$username, $emailLocalPart . '@users.noreply.gitea.local'];
    }

    private function buildAuthenticatedGitUrl(string $url, ?string $token, ?string $fullName = null): string
    {
        if (empty($token) || !str_starts_with($url, 'http')) {
            return $url;
        }

        $parsedUrl = parse_url($url);
        if (!$parsedUrl || empty($parsedUrl['scheme']) || empty($parsedUrl['host']) || empty($parsedUrl['path'])) {
            return $url;
        }

        // For Gitea over HTTP(S), use repo owner username + personal access token.
        [$username] = $this->resolveGitIdentity($fullName);

        $auth = rawurlencode($username) . ':' . rawurlencode($token) . '@';

        return $parsedUrl['scheme'] . '://' . $auth . $parsedUrl['host']
            . (isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '')
            . $parsedUrl['path'];
    }

    private function resolveTargetUrl(?string $testUrl): string
    {
        $candidate = trim((string) $testUrl);

        if ($candidate === '') {
            $candidate = (string) config('app.url', 'http://localhost:8000');
        }

        if (! str_starts_with($candidate, 'http://') && ! str_starts_with($candidate, 'https://')) {
            $candidate = 'http://' . ltrim($candidate, '/');
        }

        $parsed = parse_url($candidate);
        if ($parsed !== false && ! empty($parsed['host'])) {
            $scheme = $parsed['scheme'] ?? 'http';
            $host = $this->normalizeRuntimeHost($parsed['host']);
            $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
            $path = $parsed['path'] ?? '';
            $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
            $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

            $candidate = $scheme . '://' . $host . $port . $path . $query . $fragment;
        }

        return rtrim($candidate, '/');
    }

    private function resolveGiteaAppHost(string $targetUrl): string
    {
        $parsedUrl = parse_url($targetUrl);
        $host = $parsedUrl['host'] ?? '127.0.0.1';
        $port = $parsedUrl['port'] ?? (($parsedUrl['scheme'] ?? 'http') === 'https' ? 443 : 80);

        $host = $this->normalizeRuntimeHost($host);

        return $host . ':' . $port;
    }

    private function parseRepoFullName(string $fullName): array
    {
        $parts = explode('/', trim($fullName), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return [null, null];
        }

        return [$parts[0], $parts[1]];
    }

    private function resolveSourceBranch(Test $test): string
    {
        $branch = trim((string) ($test->source_branch ?? ''));

        return $branch !== '' ? $branch : self::DEFAULT_SOURCE_BRANCH;
    }

    private function resolveTestBranch(Test $test): string
    {
        $branch = trim((string) ($test->test_branch ?? ''));

        return $branch !== '' ? $branch : self::DEFAULT_TEST_BRANCH;
    }

    private function normalizeActionRunPayload(array $payload): array
    {
        $status = strtolower((string) ($payload['status'] ?? ''));
        $conclusion = strtolower((string) ($payload['conclusion'] ?? ''));
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

    private function fetchLatestActionRun(string $apiUrl, string $apiToken, string $owner, string $repo, string $branch, ?string $headSha = null): ?array
    {
        $queryVariants = [
            ['branch' => $branch, 'limit' => 20, 'page' => 1],
            ['branch' => $branch, 'per_page' => 20, 'page' => 1],
            ['ref' => $branch, 'limit' => 20, 'page' => 1],
            ['ref' => $branch, 'per_page' => 20, 'page' => 1],
        ];

        foreach ($queryVariants as $query) {
            $response = Http::withToken($apiToken)
                ->timeout(10)
                ->retry(1, 200)
                ->get("{$apiUrl}/repos/{$owner}/{$repo}/actions/runs", $query);

            if (! $response->successful()) {
                continue;
            }

            $json = $response->json();
            $runs = [];
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

                // Do not fall back to unrelated old runs when we are expecting a specific commit.
                continue;
            }

            return $normalizedRuns[0];
        }

        return null;
    }

    private function fetchActionRunById(string $apiUrl, string $apiToken, string $owner, string $repo, int $runId): ?array
    {
        if ($runId <= 0) {
            return null;
        }

        $response = Http::withToken($apiToken)
            ->timeout(10)
            ->retry(1, 200)
            ->get("{$apiUrl}/repos/{$owner}/{$repo}/actions/runs/{$runId}");

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        return $this->normalizeActionRunPayload($json);
    }

    private function fetchRunJobs(string $apiUrl, string $apiToken, string $owner, string $repo, int $runId): array
    {
        if ($runId <= 0) {
            return [];
        }

        $response = Http::withToken($apiToken)
            ->timeout(10)
            ->retry(1, 200)
            ->get("{$apiUrl}/repos/{$owner}/{$repo}/actions/runs/{$runId}/jobs");

        if (! $response->successful()) {
            return [];
        }

        $json = $response->json();
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

    private function extractTextFromZip(string $binary): ?string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'gitea-job-log-');
        if ($tmpFile === false) {
            return null;
        }

        try {
            File::put($tmpFile, $binary);
            $zip = new \ZipArchive();
            if ($zip->open($tmpFile) !== true) {
                return null;
            }

            $chunks = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
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
            @unlink($tmpFile);
        }
    }

    private function fetchJobLogText(string $apiUrl, string $apiToken, string $owner, string $repo, int $jobId): ?string
    {
        if ($jobId <= 0) {
            return null;
        }

        $response = Http::withToken($apiToken)
            ->timeout(20)
            ->retry(1, 200)
            ->get("{$apiUrl}/repos/{$owner}/{$repo}/actions/jobs/{$jobId}/logs");

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();
        if ($body === '') {
            return null;
        }

        $looksLikeZip = str_starts_with($body, "PK\x03\x04");
        if ($looksLikeZip) {
            $unzipped = $this->extractTextFromZip($body);

            return $unzipped === null ? null : $unzipped;
        }

        return $body;
    }

    private function appendDeltaJobLog(string $logFile, int $runId, array $job, string $fullText, array &$jobLogState): bool
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $fullText);
        $normalized = preg_replace("/\x1B\[[0-9;]*[A-Za-z]/", '', $normalized) ?? $normalized;
        $normalized = trim($normalized, "\n");
        if ($normalized === '') {
            return false;
        }

        $jobId = (int) ($job['id'] ?? 0);
        $prev = (string) ($jobLogState[$jobId]['full'] ?? '');
        if ($prev === $normalized) {
            return false;
        }

        if ($prev !== '' && str_starts_with($normalized, $prev)) {
            $delta = ltrim(substr($normalized, strlen($prev)), "\n");
            if ($delta === '') {
                return false;
            }

            File::append($logFile, $delta . "\n");
            $jobLogState[$jobId]['full'] = $normalized;

            return true;
        }

        // Log stream restarted/rotated: reprint.
        File::append($logFile, "\n{$normalized}\n");
        $jobLogState[$jobId]['full'] = $normalized;

        return true;
    }

    private function streamRunLogsToGeneratorLog(string $logFile, string $apiUrl, string $apiToken, string $owner, string $repo, int $runId, array &$jobLogState): bool
    {
        $jobs = $this->fetchRunJobs($apiUrl, $apiToken, $owner, $repo, $runId);
        if ($jobs === []) {
            return false;
        }

        $hasChanges = false;
        foreach ($jobs as $job) {
            $jobName = strtolower((string) ($job['name'] ?? ''));
            if ($jobName !== '' && ! str_contains($jobName, 'playwright')) {
                continue;
            }

            $jobId = (int) ($job['id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }

            $jobLog = $this->fetchJobLogText($apiUrl, $apiToken, $owner, $repo, $jobId);
            if ($jobLog === null) {
                continue;
            }

            $changed = $this->appendDeltaJobLog($logFile, $runId, $job, $jobLog, $jobLogState);
            $hasChanges = $hasChanges || $changed;
        }

        return $hasChanges;
    }

    private function flushFinalRunLogs(string $logFile, string $apiUrl, string $apiToken, string $owner, string $repo, int $runId, array &$jobLogState): void
    {
        // Some Gitea instances publish job logs with a short delay after run completion.
        $maxAttempts = 10;
        $stablePasses = 0;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $changed = $this->streamRunLogsToGeneratorLog($logFile, $apiUrl, $apiToken, $owner, $repo, $runId, $jobLogState);
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

    private function waitForPlaywrightActions(string $logFile, string $repoFullName, string $testBranch, string $headSha): array
    {
        $apiUrl = rtrim((string) config('services.gitea.url'), '/');
        $apiToken = (string) config('services.gitea.token');
        if ($apiUrl === '' || $apiToken === '') {
            return ['ok' => false, 'error' => 'Gitea API is not configured.'];
        }

        [$owner, $repo] = $this->parseRepoFullName($repoFullName);
        if ($owner === null || $repo === null) {
            return ['ok' => false, 'error' => 'Invalid repository name format. Expected owner/repo.'];
        }

        $waitStart = time();
        $deadline = $waitStart + self::ACTIONS_MAX_WAIT_SECONDS;
        $runId = null;
        $trackedRunId = null;
        $jobLogState = [];

        while (time() <= $deadline) {
            $run = null;
            if ($trackedRunId !== null) {
                $run = $this->fetchActionRunById($apiUrl, $apiToken, $owner, $repo, $trackedRunId);
            }

            if ($run === null) {
                $run = $this->fetchLatestActionRun($apiUrl, $apiToken, $owner, $repo, $testBranch, $headSha);
            }

            if ($run === null && $trackedRunId !== null) {
                $latestRun = $this->fetchLatestActionRun($apiUrl, $apiToken, $owner, $repo, $testBranch);
                if ($latestRun !== null) {
                    $latestRunId = (int) ($latestRun['id'] ?? 0);
                    // Accept latest run as fallback even when already completed.
                    // This prevents missing the completion marker when run-by-id lookup is temporarily unavailable.
                    if ($latestRunId >= $trackedRunId || $latestRunId === 0) {
                        $run = $latestRun;
                        $trackedRunId = $latestRunId > 0 ? $latestRunId : $trackedRunId;
                    }
                }
            }
            if ($run === null) {
                sleep(self::ACTIONS_POLL_INTERVAL_SECONDS);
                continue;
            }

            $runId = (int) ($run['id'] ?? 0);
            if ($runId > 0 && $trackedRunId === null) {
                File::append($logFile, "\nStarting Playwright tests on Gitea Actions run #{$runId}.\n");
            }
            if ($runId > 0) {
                $trackedRunId = $runId;
            }
            $status = (string) ($run['status'] ?? '');
            $conclusion = (string) ($run['conclusion'] ?? '');

            $this->streamRunLogsToGeneratorLog($logFile, $apiUrl, $apiToken, $owner, $repo, $runId, $jobLogState);

            if (in_array($status, ['completed', 'success'], true)) {
                $this->flushFinalRunLogs($logFile, $apiUrl, $apiToken, $owner, $repo, $runId, $jobLogState);
                $completedText = "Gitea Actions run #{$runId} completed";
                if ($conclusion !== '') {
                    $completedText .= " ({$conclusion})";
                }
                File::append($logFile, $completedText . ".\n");

                if ($conclusion === '' || in_array($conclusion, ['success', 'neutral', 'skipped'], true)) {
                    return ['ok' => true, 'run' => $run];
                }

                $runUrl = (string) ($run['html_url'] ?? '');
                $error = "Playwright tests failed on Gitea Actions (conclusion: {$conclusion}).";
                if ($runUrl !== '') {
                    $error .= " Run: {$runUrl}";
                }

                return ['ok' => false, 'error' => $error];
            }

            sleep(self::ACTIONS_POLL_INTERVAL_SECONDS);
        }

        $suffix = $runId !== null ? " Last seen run #{$runId}." : '';

        return ['ok' => false, 'error' => 'Timed out waiting for Playwright tests on Gitea Actions.' . $suffix];
    }

    private function buildWorkspacePaths(Test $test): array
    {
        $workspaceKey = implode('-', array_filter([
            'test-' . $test->id,
            preg_replace('/[^a-zA-Z0-9_\-]/', '_', $test->repo_name),
            preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $test->source_branch),
            preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $test->test_branch),
        ], fn(string $part): bool => $part !== ''));

        $storagePath = storage_path('app/latest-tests/' . $workspaceKey);

        return [
            'storage' => $storagePath,
            'output' => $storagePath . '/tests',
            'clone' => $storagePath . '/source',
            'generated' => $storagePath . '/generated-playwright',
        ];
    }

    private function initializeLogFile(Test $test): string
    {
        $logFile = storage_path("app/generator-logs/test-{$test->id}.log");
        $logDir = dirname($logFile);

        if (! File::exists($logDir)) {
            File::makeDirectory($logDir, 0755, true);
        }

        File::put($logFile, "Starting generator process for {$test->name}...\n");

        return $logFile;
    }

    private function prepareWorkspaceDirectories(array $paths): void
    {
        foreach (['clone', 'output', 'generated'] as $pathKey) {
            $path = (string) ($paths[$pathKey] ?? '');
            if ($path === '') {
                continue;
            }

            if (File::exists($path)) {
                File::deleteDirectory($path);
            }
        }

        File::makeDirectory($paths['output'], 0755, true);
        File::makeDirectory($paths['generated'], 0755, true);
    }

    private function appendLog(string $logFile, string $message): void
    {
        File::append($logFile, $message);
    }

    private function markTestFailed(Test $test, string $logFile, string $error, string $logPrefix = 'Error'): void
    {
        $message = trim($error);
        if ($message === '') {
            $message = 'Unknown error.';
        }

        $this->appendLog($logFile, "\n{$logPrefix}: {$message}\n");

        $test->update([
            'status' => Test::STATUS_FAILED,
            'failed_at' => now(),
            'error' => $message,
        ]);
    }

    private function cloneSourceRepository(Test $test, string $logFile, string $gitUrl, string $cloneDir, string $sourceBranch): bool
    {
        $this->appendLog($logFile, "Cloning source from {$test->repo_url} (branch: {$sourceBranch})...\n");

        $cloneProcess = Process::run(
            'git clone --branch ' . escapeshellarg($sourceBranch)
            . ' --single-branch '
            . escapeshellarg($gitUrl)
            . ' '
            . escapeshellarg($cloneDir),
            function (string $type, string $output) use ($logFile) {
            File::append($logFile, $output);
            }
        );

        if (! $cloneProcess->failed()) {
            return true;
        }

        Log::error('Git clone failed', [
            'test_id' => $test->id,
            'error' => $cloneProcess->errorOutput(),
        ]);

        $this->markTestFailed($test, $logFile, (string) $cloneProcess->errorOutput(), 'Git Clone Error');

        return false;
    }

    private function runPlaywrightGenerator(Test $test, string $logFile, string $cloneDir, string $generatedDir, string $targetUrl, string $giteaAppHost, string $testBranch): bool
    {
        $generatorPath = base_path('playwright');
        $command = 'npx ts-node src/index.ts '
            . escapeshellarg($cloneDir) . ' '
            . escapeshellarg($generatedDir)
            . ' --base-url ' . escapeshellarg($targetUrl)
            . ' --gitea-app-host ' . escapeshellarg($giteaAppHost)
            . ' --gitea-branch ' . escapeshellarg($testBranch);

        $this->appendLog($logFile, "\nRunning Playwright Generator: {$command}\n");

        $genProcess = Process::path($generatorPath)
            ->run($command, function (string $type, string $output) use ($logFile) {
                File::append($logFile, $output);
            });

        if (! $genProcess->failed()) {
            return true;
        }

        Log::error('Playwright generator failed', [
            'test_id' => $test->id,
            'error' => $genProcess->errorOutput(),
        ]);

        $this->markTestFailed($test, $logFile, (string) $genProcess->errorOutput());

        return false;
    }

    private function prepareOutputRepository(string $cloneDir, string $outputDir): void
    {
        File::copyDirectory($cloneDir, $outputDir);

        if (File::exists($outputDir . '/.git')) {
            File::deleteDirectory($outputDir . '/.git');
        }
    }

    private function publishGeneratedArtifacts(string $generatedDir, string $outputDir): void
    {
        $playwrightDir = $outputDir . '/playwright';
        if (File::exists($playwrightDir)) {
            File::deleteDirectory($playwrightDir);
        }
        File::makeDirectory($playwrightDir, 0755, true);

        foreach (self::GENERATED_ARTIFACTS as $artifact) {
            $from = $generatedDir . '/' . $artifact;
            $to = $playwrightDir . '/' . $artifact;

            if (! File::exists($from)) {
                continue;
            }

            if (File::isDirectory($from)) {
                File::copyDirectory($from, $to);
            } else {
                File::copy($from, $to);
            }
        }

        $generatedWorkflowDir = $generatedDir . '/.gitea';
        if (File::exists($generatedWorkflowDir)) {
            File::copyDirectory($generatedWorkflowDir, $outputDir . '/.gitea');
        }

        if (File::exists($generatedDir)) {
            File::deleteDirectory($generatedDir);
        }
    }

    private function pushPlaywrightBranch(Test $test, string $logFile, string $outputDir, string $gitUrl, string $gitIdentityName, string $gitIdentityEmail, string $testBranch): bool
    {
        $this->appendLog($logFile, "\nCommitting and pushing generated tests to Gitea branch {$testBranch}...\n");

        $gitCommands = [
            'git config --global --add safe.directory ' . escapeshellarg($outputDir),
            'git init -b ' . escapeshellarg($testBranch),
            'git config user.name ' . escapeshellarg($gitIdentityName),
            'git config user.email ' . escapeshellarg($gitIdentityEmail),
            'git add .',
            'git commit --allow-empty -m "test(playwright): generate tests"',
            '(git remote remove origin 2>/dev/null || true)',
            "git credential reject <<EOF\nprotocol=http\nhost=gitea\nEOF",
            'git remote add origin ' . escapeshellarg($gitUrl) . ' || git remote set-url origin ' . escapeshellarg($gitUrl),
            'git push -u origin ' . escapeshellarg($testBranch) . ' --force',
        ];

        foreach ($gitCommands as $cmd) {
            $process = Process::path($outputDir)
                ->env(['GIT_TERMINAL_PROMPT' => '0'])
                ->run($cmd, function (string $type, string $output) use ($logFile) {
                    File::append($logFile, $output);
                });

            if (! $process->failed()) {
                continue;
            }

            $isIgnorableFailure = str_contains($cmd, 'git commit')
                || str_contains($cmd, 'git credential')
                || str_contains($cmd, 'git remote remove');

            if ($isIgnorableFailure) {
                continue;
            }

            Log::error('Git command failed', [
                'test_id' => $test->id,
                'cmd' => $cmd,
                'error' => $process->errorOutput(),
            ]);

            $this->markTestFailed($test, $logFile, (string) $process->errorOutput(), "Error running [{$cmd}]");

            return false;
        }

        return true;
    }

    private function resolveHeadSha(string $outputDir): string
    {
        $headShaProcess = Process::path($outputDir)->run('git rev-parse HEAD');

        return $headShaProcess->successful() ? trim($headShaProcess->output()) : '';
    }

    private function markTestCompleted(Test $test, string $logFile): void
    {
        $this->appendLog($logFile, "\nDone.\n");

        $test->update([
            'status' => Test::STATUS_COMPLETED,
            'failed_at' => null,
            'error' => null,
            'generated_at' => now(),
        ]);
    }

    public function generateForTest(Test $test)
    {
        $test->refresh();

        if ($test->status === Test::STATUS_GENERATING) {
            Log::warning('Generator request skipped because test is already generating.', ['test_id' => $test->id]);
            return;
        }

        $test->update([
            'status' => Test::STATUS_GENERATING,
            'started_at' => now(),
            'failed_at' => null,
            'error' => null,
        ]);

        $logFile = $this->initializeLogFile($test);

        try {
            $paths = $this->buildWorkspacePaths($test);
            $this->prepareWorkspaceDirectories($paths);

            $cloneUrl = $test->repo_url;
            $giteaToken = (string) config('services.gitea.token');
            [$gitIdentityName, $gitIdentityEmail] = $this->resolveGitIdentity($test->repo_name);
            $sourceBranch = $this->resolveSourceBranch($test);
            $testBranch = $this->resolveTestBranch($test);
            $targetUrl = $this->resolveTargetUrl($test->app_url);
            $giteaAppHost = $this->resolveGiteaAppHost($targetUrl);

            // Map localhost to internal Gitea container when running in Docker.
            $cloneUrl = str_replace(['localhost:3000', '127.0.0.1:3000'], 'gitea:3000', $cloneUrl);
            $gitUrl = $this->buildAuthenticatedGitUrl($cloneUrl, $giteaToken, $test->repo_name);

            if (! $this->cloneSourceRepository($test, $logFile, $gitUrl, $paths['clone'], $sourceBranch)) {
                return;
            }

            if (! $this->runPlaywrightGenerator($test, $logFile, $paths['clone'], $paths['generated'], $targetUrl, $giteaAppHost, $testBranch)) {
                return;
            }

            $this->prepareOutputRepository($paths['clone'], $paths['output']);
            $this->publishGeneratedArtifacts($paths['generated'], $paths['output']);

            if (! $this->pushPlaywrightBranch($test, $logFile, $paths['output'], $gitUrl, $gitIdentityName, $gitIdentityEmail, $testBranch)) {
                return;
            }

            $headSha = $this->resolveHeadSha($paths['output']);

            $actionsResult = $this->waitForPlaywrightActions($logFile, (string) $test->repo_name, $testBranch, $headSha);
            if (! ($actionsResult['ok'] ?? false)) {
                $error = (string) ($actionsResult['error'] ?? 'Playwright tests failed on Gitea Actions.');
                Log::error('Playwright actions validation failed', [
                    'test_id' => $test->id,
                    'repo' => $test->repo_name,
                    'error' => $error,
                ]);
                $this->markTestFailed($test, $logFile, $error);

                return;
            }

            $this->markTestCompleted($test, $logFile);

            Log::info('Playwright generator completed successfully for ' . $test->name);
        } catch (\Throwable $e) {
            Log::error('Playwright generator exception', ['message' => $e->getMessage()]);
            $this->markTestFailed($test, $logFile, $e->getMessage(), 'Exception');
        }
    }
}
