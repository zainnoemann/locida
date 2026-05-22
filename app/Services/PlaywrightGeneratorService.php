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

    public function cancelGeneration(Test $test): bool
    {
        $test->refresh();

        if ($test->status !== Test::STATUS_GENERATING) {
            return false;
        }

        $logFile = storage_path("app/generator-logs/test-{$test->id}.log");
        $logDir = dirname($logFile);

        if (! File::exists($logDir)) {
            File::makeDirectory($logDir, 0755, true);
        }

        File::append($logFile, "\n[" . now()->format('Y-m-d H:i:s') . "] Generation cancelled by user.\n");

        $test->update([
            'status' => Test::STATUS_CANCELLED,
            'failed_at' => null,
            'error' => 'Generation cancelled by user.',
        ]);

        return true;
    }

    public function isGenerationCancelled(Test $test): bool
    {
        $test->refresh();

        return $test->status === Test::STATUS_CANCELLED;
    }

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

    private function validateTargetUrl(string $targetUrl): ?string
    {
        if (! filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            return 'App URL is invalid.';
        }

        try {
            $headResponse = Http::timeout(10)
                ->retry(1, 200)
                ->withOptions(['allow_redirects' => true])
                ->head($targetUrl);

            if ($headResponse->successful()) {
                return null;
            }

            if (in_array($headResponse->status(), [405, 501], true)) {
                $getResponse = Http::timeout(10)
                    ->retry(1, 200)
                    ->withOptions(['allow_redirects' => true])
                    ->get($targetUrl);

                if ($getResponse->successful()) {
                    return null;
                }

                return "App URL is unreachable (HTTP {$getResponse->status()}).";
            }

            return "App URL is unreachable (HTTP {$headResponse->status()}).";
        } catch (\Throwable $exception) {
            $message = strtolower($exception->getMessage());

            if (str_contains($message, 'curl error 28') || str_contains($message, 'timed out')) {
                return 'App URL timed out.';
            }

            return 'App URL is unreachable.';
        }
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

    public function pollPlaywrightActions(
        string $logFile,
        string $repoFullName,
        string $testBranch,
        string $headSha,
        Test $test,
        ?int &$trackedRunId
    ): array {
        $apiUrl = rtrim((string) config('services.gitea.url'), '/');
        $apiToken = (string) config('services.gitea.token');
        if ($apiUrl === '' || $apiToken === '') {
            return ['status' => 'error', 'error' => 'Gitea API is not configured.'];
        }

        [$owner, $repo] = $this->parseRepoFullName($repoFullName);
        if ($owner === null || $repo === null) {
            return ['status' => 'error', 'error' => 'Invalid repository name format. Expected owner/repo.'];
        }

        if ($this->isGenerationCancelled($test)) {
            return ['status' => 'cancelled', 'error' => 'Generation cancelled by user.'];
        }

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
                if ($latestRunId >= $trackedRunId || $latestRunId === 0) {
                    $run = $latestRun;
                    $trackedRunId = $latestRunId > 0 ? $latestRunId : $trackedRunId;
                }
            }
        }
        
        if ($run === null) {
            return ['status' => 'pending'];
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
        $cacheKey = "test_job_log_state_{$test->id}";
        $jobLogState = \Illuminate\Support\Facades\Cache::get($cacheKey, []);

        $this->streamRunLogsToGeneratorLog($logFile, $apiUrl, $apiToken, $owner, $repo, $runId, $jobLogState);

        // SAVE STATE TO CACHE
        \Illuminate\Support\Facades\Cache::put($cacheKey, $jobLogState, now()->addHours(1));

        if (in_array($status, ['completed', 'success'], true)) {
            $this->flushFinalRunLogs($logFile, $apiUrl, $apiToken, $owner, $repo, $runId, $jobLogState);
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
            
            $completedText = "Gitea Actions run #{$runId} completed";
            if ($conclusion !== '') {
                $completedText .= " ({$conclusion})";
            }
            File::append($logFile, $completedText . ".\n");

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
        ];
    }

    private function initializeLogFile(Test $test): string
    {
        $logFile = storage_path("app/generator-logs/test-{$test->id}.log");
        $logDir = dirname($logFile);

        if (! File::exists($logDir)) {
            File::makeDirectory($logDir, 0755, true);
        }

        File::put($logFile, "[" . now()->format('Y-m-d H:i:s') . "] Starting generator process for {$test->name}...\n");

        return $logFile;
    }

    private function prepareWorkspaceDirectories(array $paths): void
    {
        foreach (['clone', 'output'] as $pathKey) {
            $path = (string) ($paths[$pathKey] ?? '');
            if ($path === '') {
                continue;
            }

            if (File::exists($path)) {
                File::deleteDirectory($path);
            }
        }

        File::makeDirectory($paths['output'], 0755, true);
    }

    private function appendLog(string $logFile, string $message): void
    {
        File::append($logFile, $message);
    }

    public function markTestFailed(Test $test, string $logFile, string $error, string $logPrefix = 'Error'): void
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
        $this->appendLog($logFile, "[" . now()->format('Y-m-d H:i:s') . "] Cloning source from {$test->repo_url} (branch: {$sourceBranch})...\n");

        $cloneProcess = Process::run(
            'git clone --depth 1 --branch ' . escapeshellarg($sourceBranch)
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

    private function prepareOutputRepository(string $cloneDir, string $outputDir): void
    {
        File::copyDirectory($cloneDir, $outputDir);
    }

    private function publishGeneratedArtifacts(string $outputDir, string $testBranch): void
    {
        $sourceWorkflowFile = base_path('playwright/playwright.yml');
        $targetWorkflowDir = $outputDir . '/.gitea/workflows';
        
        if (File::exists($targetWorkflowDir)) {
            File::deleteDirectory($targetWorkflowDir);
        }
        File::makeDirectory($targetWorkflowDir, 0755, true);

        if (File::exists($sourceWorkflowFile)) {
            $content = File::get($sourceWorkflowFile);
            File::put($targetWorkflowDir . '/playwright.yml', $content);
        }

        $playwrightDir = $outputDir . '/playwright';
        if (File::exists($playwrightDir)) {
            File::deleteDirectory($playwrightDir);
        }
        File::makeDirectory($playwrightDir, 0755, true);

        $this->publishSupportProject(
            base_path('playwright/crawler'),
            $playwrightDir . '/crawler',
            ['src'],
            ['package.json', 'package-lock.json', 'tsconfig.json', '.env.example']
        );

        $this->publishSupportProject(
            base_path('playwright/generator'),
            $playwrightDir . '/generator',
            ['src'],
            ['package.json', 'package-lock.json', 'tsconfig.json']
        );
    }

    private function publishSupportProject(string $sourceDir, string $targetDir, array $directories, array $files): void
    {
        if (! File::exists($sourceDir)) {
            return;
        }

        if (File::exists($targetDir)) {
            File::deleteDirectory($targetDir);
        }

        File::makeDirectory($targetDir, 0755, true);

        foreach ($directories as $directory) {
            $from = $sourceDir . '/' . $directory;
            if (File::exists($from)) {
                File::copyDirectory($from, $targetDir . '/' . $directory);
            }
        }

        foreach ($files as $file) {
            $from = $sourceDir . '/' . $file;
            if (File::exists($from)) {
                File::copy($from, $targetDir . '/' . $file);
            }
        }
    }

    private function pushPlaywrightBranch(Test $test, string $logFile, string $outputDir, string $gitUrl, string $gitIdentityName, string $gitIdentityEmail, string $testBranch): bool
    {
        $this->appendLog($logFile, "\n[" . now()->format('Y-m-d H:i:s') . "] Committing and pushing Playwright crawler, generator, and workflows to Gitea branch {$testBranch}...\n");

        $gitCommands = [
            'git config --global --add safe.directory ' . escapeshellarg($outputDir),
            'git checkout -b ' . escapeshellarg($testBranch),
            'git config user.name ' . escapeshellarg($gitIdentityName),
            'git config user.email ' . escapeshellarg($gitIdentityEmail),
            'git add .',
            'git commit --allow-empty -m "test(playwright): automate crawler and generated test execution"',
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

    public function markTestCompleted(Test $test, string $logFile): void
    {
        $this->appendLog($logFile, "\n[" . now()->format('Y-m-d H:i:s') . "] Playwright report available on Gitea Actions.\n");
        $this->appendLog($logFile, "\n[" . now()->format('Y-m-d H:i:s') . "] Done.\n");

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

        if ($this->isGenerationCancelled($test)) {
            return;
        }

        try {
            $paths = $this->buildWorkspacePaths($test);
            $this->prepareWorkspaceDirectories($paths);

            if ($this->isGenerationCancelled($test)) {
                return;
            }

            $cloneUrl = $test->repo_url;
            $giteaToken = (string) config('services.gitea.token');
            [$gitIdentityName, $gitIdentityEmail] = $this->resolveGitIdentity($test->repo_name);
            $sourceBranch = $this->resolveSourceBranch($test);
            $testBranch = $this->resolveTestBranch($test);
            $targetUrl = $this->resolveTargetUrl($test->app_url);

            $this->appendLog($logFile, "[" . now()->format('Y-m-d H:i:s') . "] Validating App URL accessibility: {$targetUrl}\n");
            $targetUrlValidationError = $this->validateTargetUrl($targetUrl);
            if ($targetUrlValidationError !== null) {
                if ($this->isGenerationCancelled($test)) {
                    return;
                }

                $this->markTestFailed($test, $logFile, $targetUrlValidationError, 'App URL Validation Error');

                return;
            }
            $this->appendLog($logFile, "[" . now()->format('Y-m-d H:i:s') . "] App URL validation passed.\n");

            if ($this->isGenerationCancelled($test)) {
                return;
            }

            // Map localhost to internal Gitea container when running in Docker.
            $cloneUrl = str_replace(['localhost:3000', '127.0.0.1:3000'], 'gitea:3000', $cloneUrl);
            $gitUrl = $this->buildAuthenticatedGitUrl($cloneUrl, $giteaToken, $test->repo_name);

            if (! $this->cloneSourceRepository($test, $logFile, $gitUrl, $paths['clone'], $sourceBranch)) {
                if ($this->isGenerationCancelled($test)) {
                    return;
                }

                return;
            }

            if ($this->isGenerationCancelled($test)) {
                return;
            }

            $this->prepareOutputRepository($paths['clone'], $paths['output']);
            $this->appendLog($logFile, "[" . now()->format('Y-m-d H:i:s') . "] Staging Playwright crawler, generator, and workflows for Gitea Actions...\n");
            $this->publishGeneratedArtifacts($paths['output'], $testBranch);

            if ($this->isGenerationCancelled($test)) {
                return;
            }

            if (! $this->pushPlaywrightBranch($test, $logFile, $paths['output'], $gitUrl, $gitIdentityName, $gitIdentityEmail, $testBranch)) {
                if ($this->isGenerationCancelled($test)) {
                    return;
                }

                return;
            }

            if ($this->isGenerationCancelled($test)) {
                return;
            }

            $headSha = $this->resolveHeadSha($paths['output']);

            $this->appendLog($logFile, "\n[" . now()->format('Y-m-d H:i:s') . "] Dispatching polling job to monitor Gitea Actions...\n");
            
            $deadline = time() + self::ACTIONS_MAX_WAIT_SECONDS;
            \App\Jobs\PollPlaywrightJob::dispatch(
                $test,
                $logFile,
                (string) $test->repo_name,
                $testBranch,
                $headSha,
                $deadline,
                null
            );

            return; // Eksekusi akan dilanjutkan oleh PollPlaywrightActionsJob
        } catch (\Throwable $e) {
            Log::error('Playwright generator exception', ['message' => $e->getMessage()]);
            $this->markTestFailed($test, $logFile, $e->getMessage(), 'Exception');
        }
    }
}
