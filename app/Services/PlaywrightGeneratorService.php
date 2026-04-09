<?php

namespace App\Services;

use App\Models\Test;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class PlaywrightGeneratorService
{
    private function buildAuthenticatedGitUrl(string $url, ?string $token, ?string $fullName = null): string
    {
        if (empty($token) || !str_starts_with($url, 'http')) {
            return $url;
        }

        $parsedUrl = parse_url($url);
        if (!$parsedUrl || empty($parsedUrl['scheme']) || empty($parsedUrl['host']) || empty($parsedUrl['path'])) {
            return $url;
        }

        // For Gitea over HTTP(S), use username + personal access token as password.
        $username = 'git';
        if (!empty($fullName) && str_contains($fullName, '/')) {
            [$owner] = explode('/', $fullName, 2);
            if (!empty($owner)) {
                $username = $owner;
            }
        }

        $auth = rawurlencode($username) . ':' . rawurlencode($token) . '@';

        return $parsedUrl['scheme'] . '://' . $auth . $parsedUrl['host']
            . (isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '')
            . $parsedUrl['path'];
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

        try {
            $logFile = storage_path("app/generator-logs/test-{$test->id}.log");
            if (!File::exists(dirname($logFile))) {
                File::makeDirectory(dirname($logFile), 0755, true);
            }
            File::put($logFile, "Starting generator process for {$test->name}...\n");

            // Define storage path for the repo
            $storagePath = storage_path('app/latest-tests/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $test->repo_name));
            $outputDir = $storagePath . '/tests';
            $cloneDir = $storagePath . '/source';
            $generatedDir = $storagePath . '/generated-playwright';

            // Clean up old source if needed
            if (File::exists($cloneDir)) {
                File::deleteDirectory($cloneDir);
            }
            if (File::exists($outputDir)) {
                File::deleteDirectory($outputDir);
            }
            if (File::exists($generatedDir)) {
                File::deleteDirectory($generatedDir);
            }
            File::makeDirectory($outputDir, 0755, true);
            File::makeDirectory($generatedDir, 0755, true);

            // Clone source project
            // Assuming the repo_url contains credentials or the system has SSH access/public repo.
            $cloneUrl = $test->repo_url;
            $giteaToken = env('GITEA_API_TOKEN');

            // Map localhost to the internal gitea container if running in Docker
            $cloneUrl = str_replace(['localhost:3000', '127.0.0.1:3000'], 'gitea:3000', $cloneUrl);

            // Build authenticated URL for private clone/push when token is provided.
            $gitUrl = $this->buildAuthenticatedGitUrl($cloneUrl, $giteaToken, $test->repo_name);

            File::append($logFile, "Cloning source from {$test->repo_url}...\n");
            $cloneProcess = Process::run('git clone ' . escapeshellarg($gitUrl) . ' ' . escapeshellarg($cloneDir), function (string $type, string $output) use ($logFile) {
                File::append($logFile, $output);
            });

            if ($cloneProcess->failed()) {
                Log::error('Git clone failed', ['error' => $cloneProcess->errorOutput()]);
                File::append($logFile, "\nGit Clone Error: " . $cloneProcess->errorOutput());
                $test->update([
                    'status' => Test::STATUS_FAILED,
                    'failed_at' => now(),
                    'error' => trim($cloneProcess->errorOutput()),
                ]);
                return;
            }

            // Run playwright generator
            // Example command from README: npx ts-node src/index.ts <laravel-path> [output-dir] [options]
            $generatorPath = base_path('playwright');
            $command = "npx ts-node src/index.ts {$cloneDir} {$generatedDir} --gitea-branch playwright";

            File::append($logFile, "\nRunning Playwright Generator: {$command}\n");

            $genProcess = Process::path($generatorPath)
                ->run($command, function (string $type, string $output) use ($logFile) {
                    File::append($logFile, $output);
                });

            if ($genProcess->failed()) {
                Log::error('Playwright generator failed', ['error' => $genProcess->errorOutput()]);
                File::append($logFile, "\nError: " . $genProcess->errorOutput());
                $test->update([
                    'status' => Test::STATUS_FAILED,
                    'failed_at' => now(),
                    'error' => trim($genProcess->errorOutput()),
                ]);
                return;
            }

            // Put source code at repository root.
            File::copyDirectory($cloneDir, $outputDir);
            if (File::exists($outputDir . '/.git')) {
                File::deleteDirectory($outputDir . '/.git');
            }

            // Playwright artifacts in a single playwright/ directory.
            $playwrightDir = $outputDir . '/playwright';
            if (File::exists($playwrightDir)) {
                File::deleteDirectory($playwrightDir);
            }
            File::makeDirectory($playwrightDir, 0755, true);

            $generatedArtifacts = [
                'fixtures',
                'pages',
                'tests',
                'playwright.config.ts',
                'package.json',
                'tsconfig.json',
            ];
            foreach ($generatedArtifacts as $artifact) {
                $from = $generatedDir . '/' . $artifact;
                $to = $playwrightDir . '/' . $artifact;
                if (!File::exists($from)) {
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

            File::append($logFile, "\nCommitting and pushing generated tests to Gitea...\n");

            $gitCommands = [
                'git config --global --add safe.directory ' . escapeshellarg($outputDir),
                "git init -b playwright",
                "git config user.name 'locida'",
                "git config user.email 'locida@mail.com'",
                "git config user.useConfigOnly true",
                "git add .",
                "git commit -m \"test(playwright): generate tests\"",
                // Remove existing origin if present, ignore errors
                "(git remote remove origin 2>/dev/null || true)",
                // Clear credential cache for the host to prevent interference
                "git credential reject <<EOF\nprotocol=http\nhost=gitea\nEOF",
                // Add or update origin with authenticated URL
                'git remote add origin ' . escapeshellarg($gitUrl) . ' || git remote set-url origin ' . escapeshellarg($gitUrl),
                "git push -u origin playwright --force"
            ];

            foreach ($gitCommands as $cmd) {
                $process = Process::path($outputDir)
                    ->env(['GIT_TERMINAL_PROMPT' => '0'])
                    ->run($cmd, function (string $type, string $output) use ($logFile) {
                        File::append($logFile, $output);
                    });

                if ($process->failed() && strpos($cmd, 'git commit') === false && strpos($cmd, 'git credential') === false && strpos($cmd, 'git remote remove') === false) {
                    Log::error("Git command failed", ['cmd' => $cmd, 'error' => $process->errorOutput()]);
                    File::append($logFile, "\nError running [{$cmd}]:\n" . $process->errorOutput());

                    $test->update([
                        'status' => Test::STATUS_FAILED,
                        'failed_at' => now(),
                        'error' => trim($process->errorOutput()),
                    ]);

                    return;
                }
            }

            File::append($logFile, "\nDone.\n");

            $test->update([
                'status' => Test::STATUS_COMPLETED,
                'failed_at' => null,
                'error' => null,
                'generated_at' => now(),
            ]);

            Log::info('Playwright generator completed successfully for ' . $test->name);
        } catch (\Exception $e) {
            Log::error('Playwright generator exception', ['message' => $e->getMessage()]);
            $logFile = storage_path("app/generator-logs/test-{$test->id}.log");
            if (! File::exists(dirname($logFile))) {
                File::makeDirectory(dirname($logFile), 0755, true);
            }
            File::append($logFile, "\nException: {$e->getMessage()}\n");

            $test->update([
                'status' => Test::STATUS_FAILED,
                'failed_at' => now(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
