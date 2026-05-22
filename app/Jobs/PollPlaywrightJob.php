<?php

namespace App\Jobs;

use App\Models\Test;
use App\Services\PlaywrightGeneratorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PollPlaywrightJob implements ShouldQueue
{
    use Queueable;

    public $test;
    public $logFile;
    public $repoFullName;
    public $testBranch;
    public $headSha;
    public $deadline;
    public $trackedRunId;

    public $timeout = 120; // 2 minutes max per run
    public $tries = 1000; // allow many retries because we use release

    public function __construct(
        Test $test,
        string $logFile,
        string $repoFullName,
        string $testBranch,
        string $headSha,
        int $deadline,
        ?int $trackedRunId = null
    ) {
        $this->test = $test;
        $this->logFile = $logFile;
        $this->repoFullName = $repoFullName;
        $this->testBranch = $testBranch;
        $this->headSha = $headSha;
        $this->deadline = $deadline;
        $this->trackedRunId = $trackedRunId;
    }

    public function handle(PlaywrightGeneratorService $generatorService): void
    {
        if (time() > $this->deadline) {
            $suffix = $this->trackedRunId !== null ? " Last seen run #{$this->trackedRunId}." : '';
            $error = 'Timed out waiting for Playwright tests on Gitea Actions.' . $suffix;
            Log::error('Playwright actions validation timed out', [
                'test_id' => $this->test->id,
                'repo' => $this->repoFullName,
                'error' => $error,
            ]);
            $generatorService->markTestFailed($this->test, $this->logFile, $error);
            return;
        }

        $result = $generatorService->pollPlaywrightActions(
            $this->logFile,
            $this->repoFullName,
            $this->testBranch,
            $this->headSha,
            $this->test,
            $this->trackedRunId
        );

        if ($result['status'] === 'pending') {
            // Re-queue this job to run again after 5 seconds
            $this->release(5);
            return;
        }

        if ($result['status'] === 'cancelled') {
            return;
        }

        if ($result['status'] === 'error') {
            $error = (string) ($result['error'] ?? 'Playwright tests failed on Gitea Actions.');
            Log::error('Playwright actions validation failed', [
                'test_id' => $this->test->id,
                'repo' => $this->repoFullName,
                'error' => $error,
            ]);
            $generatorService->markTestFailed($this->test, $this->logFile, $error);
            return;
        }

        if ($result['status'] === 'completed') {
            $generatorService->markTestCompleted($this->test, $this->logFile);
            Log::info('Playwright generator completed successfully for ' . $this->test->name);
            return;
        }
    }
}
