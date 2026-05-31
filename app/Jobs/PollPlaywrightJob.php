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

    public ?int $trackedRunId;

    public int $timeout = 120; // 2 minutes max per run
    public int $tries = 1000; // allow many retries because we use release

    public function __construct(
        public readonly int $testId,
        public readonly string $logFile,
        public readonly string $repoFullName,
        public readonly string $testBranch,
        public readonly string $headSha,
        public readonly int $deadline,
        ?int $trackedRunId = null
    ) {
        $this->trackedRunId = $trackedRunId;
    }

    public function handle(PlaywrightGeneratorService $generatorService): void
    {
        $test = Test::query()->find($this->testId);

        if (! $test) {
            return;
        }

        if (time() > $this->deadline) {
            $suffix = $this->trackedRunId !== null ? " Last seen run #{$this->trackedRunId}." : '';
            $error = 'Timed out waiting for Playwright tests on Gitea Actions.' . $suffix;
            Log::error('Playwright actions validation timed out', [
                'test_id' => $test->id,
                'repo' => $this->repoFullName,
                'error' => $error,
            ]);
            $generatorService->markTestFailed($test, $this->logFile, $error);
            return;
        }

        $result = $generatorService->pollPlaywrightActions(
            $this->logFile,
            $this->repoFullName,
            $this->testBranch,
            $this->headSha,
            $test,
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
                'test_id' => $test->id,
                'repo' => $this->repoFullName,
                'error' => $error,
            ]);
            $generatorService->markTestFailed($test, $this->logFile, $error);
            return;
        }

        if ($result['status'] === 'completed') {
            $generatorService->markTestCompleted($test, $this->logFile);
            Log::info('Playwright generator completed successfully for ' . $test->name);
            return;
        }
    }
}
