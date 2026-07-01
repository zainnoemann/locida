<?php

namespace App\Services;

use App\Contracts\GitInterface;
use App\Jobs\PollTestJob;
use App\Models\Test;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Core service for managing the lifecycle of Playwright test generation.
 * Handles the orchestration of git operations, workspace preparation,
 * and delegates specific tasks to crawler, generator, and runner services.
 */
class TestService
{
    /**
     * Maximum time in seconds to wait for Gitea Actions to complete.
     * 900 seconds = 15 minutes.
     */
    private const ACTIONS_MAX_WAIT_SECONDS = 900;

    private const DEFAULT_SOURCE_BRANCH = 'main';
    private const DEFAULT_TEST_BRANCH = 'playwright';

    public function __construct(
        private GitInterface $git,
        private WebCrawlerService $crawler,
        private ScriptGeneratorService $generator,
        private TestRunnerService $runner
    ) {
    }

    /**
     * Halts an ongoing test generation process and updates its status.
     * Appends a cancellation notice to the generation log file.
     *
     * @param Test $test The test instance to cancel.
     * @return bool True if successfully cancelled, false if the test was not generating.
     */
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

    /**
     * Checks whether the current test generation process has been cancelled.
     *
     * @param Test $test
     * @return bool
     */
    public function isGenerationCancelled(Test $test): bool
    {
        $test->refresh();

        return $test->status === Test::STATUS_CANCELLED;
    }

    /**
     * Creates and initializes the log file for a new test generation process.
     *
     * @param Test $test
     * @return string The absolute path to the initialized log file.
     */
    public function initializeLogFile(Test $test): string
    {
        $logFile = storage_path("app/generator-logs/test-{$test->id}.log");
        $logDir = dirname($logFile);

        if (! File::exists($logDir)) {
            File::makeDirectory($logDir, 0755, true);
        }

        File::put($logFile, "[" . now()->format('Y-m-d H:i:s') . "] Starting generator process for {$test->name}...\n");

        return $logFile;
    }

    /**
     * Appends a message to the specified log file.
     *
     * @param string $logFile
     * @param string $message
     */
    public function appendLog(string $logFile, string $message): void
    {
        File::append($logFile, $message);
    }

    /**
     * Marks a test as failed, logs the error, and updates the database record.
     *
     * @param Test $test
     * @param string $logFile
     * @param string $error The error message to persist.
     * @param string $logPrefix Prefix for the error line in the log file.
     */
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

    /**
     * Marks a test generation process as successfully completed.
     *
     * @param Test $test
     * @param string $logFile
     */
    public function markTestCompleted(Test $test, string $logFile): void
    {
        $this->appendLog($logFile, "\n[" . now()->format('Y-m-d H:i:s') . "] Playwright report available on Git Actions.\n");
        $this->appendLog($logFile, "\n[" . now()->format('Y-m-d H:i:s') . "] Done.\n");

        $test->update([
            'status' => Test::STATUS_COMPLETED,
            'failed_at' => null,
            'error' => null,
            'generated_at' => now(),
        ]);
    }

    /**
     * Delegates the polling of Playwright action workflows in the Git to the runner service.
     *
     * @param string $logFile
     * @param string $repoFullName
     * @param string $testBranch
     * @param string $headSha
     * @param Test $test
     * @param int|null &$trackedRunId Reference to the current run ID being monitored.
     * @return array Status information from the runner service.
     */
    public function pollPlaywrightActions(string $logFile, string $repoFullName, string $testBranch, string $headSha, Test $test, ?int &$trackedRunId): array
    {
        return $this->runner->pollPlaywrightActions($logFile, $repoFullName, $testBranch, $headSha, $test, $trackedRunId);
    }

    /**
     * Main orchestration method for generating a Playwright test suite.
     * Handles repository cloning, workspace setup, tool staging, and remote pushing.
     * Dispatches a polling job at the end to monitor the remote action execution.
     *
     * @param Test $test
     */
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
            $gitToken = $this->git->getToken();
            [$gitIdentityName, $gitIdentityEmail] = $this->resolveGitIdentity($test->repo_name);
            $sourceBranch = $this->resolveSourceBranch($test);
            $testBranch = $this->resolveTestBranch($test);


            if ($this->isGenerationCancelled($test)) {
                return;
            }

            // Substitute localhost references for Docker network compatibility
            $cloneUrl = str_replace(['localhost:3000', '127.0.0.1:3000'], 'gitea:3000', $cloneUrl);
            $gitUrl = $this->buildAuthenticatedGitUrl($cloneUrl, $gitToken, $test->repo_name);

            // Pass log append callback
            $logger = function ($msg) use ($logFile) {
                $this->appendLog($logFile, $msg);
            };
            $failureHandler = function ($t, $lf, $err, $pref) {
                $this->markTestFailed($t, $lf, $err, $pref);
            };

            if (! $this->cloneSourceRepository($test, $logFile, $gitUrl, $paths['clone'], $sourceBranch)) {
                return;
            }

            if ($this->isGenerationCancelled($test)) {
                return;
            }

            $this->prepareOutputRepository($paths['clone'], $paths['output']);
            $this->appendLog($logFile, "[" . now()->format('Y-m-d H:i:s') . "] Staging Playwright crawler, generator, and workflows...\n");

            $this->crawler->prepareCrawler($paths['output']);
            $this->generator->prepareGenerator($paths['output']);
            $this->runner->prepareWorkflow($paths['output'], $test);

            if ($this->isGenerationCancelled($test)) {
                return;
            }

            if (! $this->pushPlaywrightBranch($test, $logFile, $paths['output'], $gitUrl, $gitIdentityName, $gitIdentityEmail, $testBranch)) {
                return;
            }

            if ($this->isGenerationCancelled($test)) {
                return;
            }

            $headSha = $this->resolveHeadSha($paths['output']);

            $this->appendLog($logFile, "\n[" . now()->format('Y-m-d H:i:s') . "] Dispatching polling job to monitor Actions...\n");

            $deadline = time() + self::ACTIONS_MAX_WAIT_SECONDS;
            PollTestJob::dispatch(
                $test->id,
                $logFile,
                (string) $test->repo_name,
                $testBranch,
                $headSha,
                $deadline,
                null
            );

        } catch (Throwable $e) {
            Log::error('Playwright generator exception', ['message' => $e->getMessage()]);
            $this->markTestFailed($test, $logFile, $e->getMessage(), 'Exception');
        }
    }

    /**
     * Retrieves the source branch name, defaulting if not set.
     *
     * @param Test $test
     * @return string
     */
    public function resolveSourceBranch(Test $test): string
    {
        $branch = trim((string) ($test->source_branch ?? ''));

        return $branch !== '' ? $branch : self::DEFAULT_SOURCE_BRANCH;
    }

    /**
     * Retrieves the target test branch name, defaulting if not set.
     *
     * @param Test $test
     * @return string
     */
    public function resolveTestBranch(Test $test): string
    {
        $branch = trim((string) ($test->test_branch ?? ''));

        return $branch !== '' ? $branch : self::DEFAULT_TEST_BRANCH;
    }

    /**
     * Determines the git identity (username and email) based on the repository owner.
     * This ensures commits pushed back to Gitea belong to the correct bot or owner account.
     *
     * @param string|null $fullName The repository full name (e.g., 'owner/repo')
     * @return array Contains [username, email]
     */
    public function resolveGitIdentity(?string $fullName): array
    {
        $username = 'locida-bot';

        if (! empty($fullName) && str_contains($fullName, '/')) {
            [$owner] = explode('/', $fullName, 2);
            if (! empty($owner)) {
                $username = $owner;
            }
        }

        $emailLocalPart = preg_replace('/[^a-zA-Z0-9._-]/', '', $username);
        if (empty($emailLocalPart)) {
            $emailLocalPart = 'locida-bot';
        }

        return [$username, $emailLocalPart . '@users.noreply.git.local'];
    }

    /**
     * Constructs a Git URL embedded with credentials for authenticated git operations.
     *
     * @param string $url The base Git HTTP clone URL.
     * @param string|null $token Personal access token or equivalent auth token.
     * @param string|null $fullName Repository full name used to derive the authenticating username.
     * @return string The authenticated URL.
     */
    public function buildAuthenticatedGitUrl(string $url, ?string $token, ?string $fullName = null): string
    {
        if (empty($token) || !str_starts_with($url, 'http')) {
            return $url;
        }

        $parsedUrl = parse_url($url);
        if (!$parsedUrl || empty($parsedUrl['scheme']) || empty($parsedUrl['host']) || empty($parsedUrl['path'])) {
            return $url;
        }

        // Use repo owner username + personal access token.
        [$username] = $this->resolveGitIdentity($fullName);

        $auth = rawurlencode($username) . ':' . rawurlencode($token) . '@';

        return $parsedUrl['scheme'] . '://' . $auth . $parsedUrl['host']
            . (isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '')
            . $parsedUrl['path'];
    }

    /**
     * Generates standard file paths needed for testing workspace isolated per run.
     *
     * @param Test $test
     * @return array Paths for 'storage', 'output', and 'clone' directories.
     */
    public function buildWorkspacePaths(Test $test): array
    {
        $workspaceKey = implode('-', array_filter([
            'test-' . $test->id,
            preg_replace('/[^a-zA-Z0-9_\-]/', '_', $test->repo_name),
            preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $test->source_branch),
            preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $test->test_branch),
        ], fn (string $part): bool => $part !== ''));

        $storagePath = storage_path('app/latest-tests/' . $workspaceKey);

        return [
            'storage' => $storagePath,
            'output' => $storagePath . '/tests',
            'clone' => $storagePath . '/source',
        ];
    }

    /**
     * Cleans up existing directories to ensure a fresh state, then recreates the output path.
     *
     * @param array $paths Array containing workspace paths.
     */
    public function prepareWorkspaceDirectories(array $paths): void
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

    /**
     * Splits a full repository name into its owner and repository components.
     *
     * @param string $fullName Formatted as "owner/repo"
     * @return array [owner, repo] or [null, null] if parsing fails.
     */
    public function parseRepoFullName(string $fullName): array
    {
        $parts = explode('/', trim($fullName), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return [null, null];
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * Retrieves the latest commit SHA for the current HEAD in the specified output directory.
     *
     * @param string $outputDir The path to the local git repository.
     * @return string The commit SHA, or empty string on failure.
     */
    public function resolveHeadSha(string $outputDir): string
    {
        $headShaProcess = Process::path($outputDir)->run('git rev-parse HEAD');

        return $headShaProcess->successful() ? trim($headShaProcess->output()) : '';
    }

    /**
     * Copies the cloned repository into the output directory where Playwright configuration will be staged.
     *
     * @param string $cloneDir The initially cloned repository path.
     * @param string $outputDir The target working directory.
     */
    public function prepareOutputRepository(string $cloneDir, string $outputDir): void
    {
        File::copyDirectory($cloneDir, $outputDir);
    }

    /**
     * Copies support files and directories from a source template location to the target output location.
     * Used by crawler, generator, and runner services to inject Playwright scaffolds.
     *
     * @param string $sourceDir Directory containing template files.
     * @param string $targetDir Destination directory.
     * @param array $directories List of directories to copy recursively.
     * @param array $files List of explicit files to copy.
     */
    public function publishSupportProject(string $sourceDir, string $targetDir, array $directories, array $files): void
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

    /**
     * Executes the git clone command to fetch the source repository for inspection and generation.
     *
     * @param Test $test
     * @param string $logFile
     * @param string $gitUrl Authenticated clone URL.
     * @param string $cloneDir Destination directory for clone.
     * @param string $sourceBranch Target branch to checkout.
     * @return bool True if cloning succeeded, false otherwise.
     */
    public function cloneSourceRepository(Test $test, string $logFile, string $gitUrl, string $cloneDir, string $sourceBranch): bool
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

    /**
     * Commits and pushes the generated Playwright artifacts back to the configured Gitea repository.
     * Creates or overwrites the target testing branch to trigger remote CI/CD workflows.
     *
     * @param Test $test
     * @param string $logFile
     * @param string $outputDir The local workspace with staged changes.
     * @param string $gitUrl Authenticated URL to push to.
     * @param string $gitIdentityName
     * @param string $gitIdentityEmail
     * @param string $testBranch The target branch where the generated code should be pushed.
     * @return bool True if successful, false on unrecoverable failure.
     */
    public function pushPlaywrightBranch(Test $test, string $logFile, string $outputDir, string $gitUrl, string $gitIdentityName, string $gitIdentityEmail, string $testBranch): bool
    {
        $this->appendLog($logFile, "\n[" . now()->format('Y-m-d H:i:s') . "] Committing and pushing Playwright crawler, generator, and workflows to branch {$testBranch}...\n");

        $gitCommands = [
            'git config --global --add safe.directory ' . escapeshellarg($outputDir),
            'git checkout -b ' . escapeshellarg($testBranch),
            'git config user.name ' . escapeshellarg($gitIdentityName),
            'git config user.email ' . escapeshellarg($gitIdentityEmail),
            'git add .',
            'git commit --allow-empty -m "chore(playwright): setup automation for crawler and test generator"',
            '(git remote remove origin 2>/dev/null || true)',
            "git credential reject <<EOF\nprotocol=https\nhost=github.com\nEOF",
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

            // Some commands may fail without breaking the flow, like attempting to remove a remote that doesn't exist.
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
}
