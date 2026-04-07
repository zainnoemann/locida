<?php

namespace App\Services;

use App\Models\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class AppGeneratorService
{
    private function buildAuthenticatedGitUrl(string $url, ?string $token, ?string $fullName = null): string
    {
        if (empty($token) || ! str_starts_with($url, 'http')) {
            return $url;
        }

        $parsedUrl = parse_url($url);
        if (! $parsedUrl || empty($parsedUrl['scheme']) || empty($parsedUrl['host']) || empty($parsedUrl['path'])) {
            return $url;
        }

        $username = 'git';
        if (! empty($fullName) && str_contains($fullName, '/')) {
            [$owner] = explode('/', $fullName, 2);
            if (! empty($owner)) {
                $username = $owner;
            }
        }

        $auth = rawurlencode($username) . ':' . rawurlencode($token) . '@';

        return $parsedUrl['scheme'] . '://' . $auth . $parsedUrl['host']
            . (isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '')
            . $parsedUrl['path'];
    }

    public function generateForApp(App $app): void
    {
        $app->refresh();

        if ($app->status === App::STATUS_GENERATING) {
            Log::warning('App generator request skipped because app is already generating.', ['app_id' => $app->id]);
            return;
        }

        $app->update([
            'status' => App::STATUS_GENERATING,
            'started_at' => now(),
            'failed_at' => null,
            'error' => null,
        ]);

        $logFile = storage_path("app/generator-logs/app-{$app->id}.log");

        try {
            if (! File::exists(dirname($logFile))) {
                File::makeDirectory(dirname($logFile), 0755, true);
            }

            File::put($logFile, "Starting generator process for {$app->name}...\n");

            $storagePath = storage_path('app/latest-apps/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $app->repo_name));
            $cloneDir = $storagePath . '/source';
            $dockerDir = $cloneDir . '/docker';

            if (File::exists($cloneDir)) {
                File::deleteDirectory($cloneDir);
            }
            File::ensureDirectoryExists($storagePath);

            $cloneUrl = $app->repo_url;
            $giteaToken = env('GITEA_API_TOKEN');
            $cloneUrl = str_replace(['localhost:3000', '127.0.0.1:3000'], 'gitea:3000', $cloneUrl);
            $gitUrl = $this->buildAuthenticatedGitUrl($cloneUrl, $giteaToken, $app->repo_name);

            File::append($logFile, "Cloning source from {$app->repo_url}...\n");
            $cloneProcess = Process::timeout(300)
                ->run('git clone ' . escapeshellarg($gitUrl) . ' ' . escapeshellarg($cloneDir), function (string $type, string $output) use ($logFile) {
                    File::append($logFile, $output);
                });

            if ($cloneProcess->failed()) {
                $this->failRun($app, $logFile, 'Git Clone Error: ' . trim($cloneProcess->errorOutput()));
                return;
            }

            $generatorPath = base_path('docker/runner');
            $command = "npx ts-node src/index.ts {$cloneDir} {$dockerDir} --app-name {$app->name} --gitea-branch runner";

            File::append($logFile, "\nRunning App Generator: {$command}\n");
            $genProcess = Process::path($generatorPath)
                ->timeout(900)
                ->run($command, function (string $type, string $output) use ($logFile) {
                    File::append($logFile, $output);
                });

            if ($genProcess->failed()) {
                $this->failRun($app, $logFile, 'App Generator Error: ' . trim($genProcess->errorOutput()));
                return;
            }

            File::append($logFile, "\nCommitting and pushing generated setup to Gitea branch runner...\n");

            $gitCommands = [
                'git config --global --add safe.directory ' . escapeshellarg($cloneDir),
                'git init',
                "git config user.name 'locida'",
                "git config user.email 'locida@mail.com'",
                "git config user.useConfigOnly true",
                'git add .',
                "git commit -m \"chore: generate app setup artifacts\"",
                'git branch -M runner',
                '(git remote remove origin 2>/dev/null || true)',
                'git remote add origin ' . escapeshellarg($gitUrl) . ' || git remote set-url origin ' . escapeshellarg($gitUrl),
                'git push -u origin runner --force',
            ];

            foreach ($gitCommands as $cmd) {
                $process = Process::path($cloneDir)
                    ->env(['GIT_TERMINAL_PROMPT' => '0'])
                    ->timeout(300)
                    ->run($cmd, function (string $type, string $output) use ($logFile) {
                        File::append($logFile, $output);
                    });

                if ($process->failed() && strpos($cmd, 'git commit') === false && strpos($cmd, 'git remote remove') === false) {
                    Log::error('Git command failed', ['cmd' => $cmd, 'error' => $process->errorOutput()]);
                    File::append($logFile, "\nError running [{$cmd}]:\n" . $process->errorOutput());

                    $app->update([
                        'status' => App::STATUS_FAILED,
                        'failed_at' => now(),
                        'error' => trim($process->errorOutput()),
                    ]);

                    return;
                }
            }

            File::append($logFile, "\nDone.\n");

            $app->update([
                'status' => App::STATUS_COMPLETED,
                'failed_at' => null,
                'error' => null,
                'generated_at' => now(),
            ]);

            Log::info('App generator completed successfully.', ['app_id' => $app->id, 'name' => $app->name]);
        } catch (\Throwable $e) {
            Log::error('App generator exception', ['app_id' => $app->id, 'message' => $e->getMessage()]);

            if (! File::exists(dirname($logFile))) {
                File::makeDirectory(dirname($logFile), 0755, true);
            }
            File::append($logFile, "\nException: {$e->getMessage()}\n");

            $app->update([
                'status' => App::STATUS_FAILED,
                'failed_at' => now(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function failRun(App $app, string $logFile, string $message): void
    {
        Log::error('App generator failed', ['app_id' => $app->id, 'error' => $message]);
        File::append($logFile, "\n{$message}\n");

        $app->update([
            'status' => App::STATUS_FAILED,
            'failed_at' => now(),
            'error' => $message,
        ]);
    }
}
