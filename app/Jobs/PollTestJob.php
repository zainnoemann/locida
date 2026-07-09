<?php

namespace App\Jobs;

use App\Models\Test;
use App\Services\TestService;
use App\Services\PipelineService;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Recursive background job designed to poll Gitea Actions for test execution progress.
 * If the action is still pending or running, the job releases itself back onto the queue
 * after a brief delay, creating a continuous monitoring loop until a terminal state is reached.
 */
class PollTestJob implements ShouldQueue
{
    use Queueable;

    /**
     * The Gitea Action run ID currently being monitored.
     * Passed continuously through released jobs to avoid fetching the wrong run.
     *
     * @var int|null
     */
    public ?int $trackedRunId;

    /**
     * Hard timeout per individual polling iteration to prevent zombie jobs.
     * Note: This restricts a *single run* of this job, not the entire polling lifecycle.
     *
     * @var int
     */
    public int $timeout = 120; // 2 minutes max per iteration

    /**
     * Create a new polling job instance.
     *
     * @param int $testId The ID of the test being tracked.
     * @param string $logFile Path to the local generator log file to append output to.
     * @param string $repoFullName Target repository in "owner/repo" format.
     * @param string $testBranch Branch where Playwright tests are being executed.
     * @param string $headSha Target commit SHA to correlate the exact Gitea action run.
     * @param int $deadline Unix timestamp representing the absolute maximum wait time for the entire polling lifecycle.
     * @param int|null $trackedRunId Known run ID, if any.
     */
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

    /**
     * Define the absolute time at which the job should permanently fail.
     * Laravel's queue worker respects this over the standard attempts limit.
     *
     * @return DateTimeInterface
     */
    public function retryUntil(): DateTimeInterface
    {
        return Carbon::createFromTimestamp($this->deadline);
    }

    /**
     * Execute the job polling iteration.
     *
     * @param TestService $testService
     */
    public function handle(TestService $testService, PipelineService $pipelineService): void
    {
        $test = Test::query()->find($this->testId);

        if (! $test) {
            return;
        }

        // Global lifecycle timeout check
        if (time() > $this->deadline) {
            $suffix = $this->trackedRunId !== null ? " Last seen run #{$this->trackedRunId}." : '';
            $error = 'Timed out waiting for Playwright tests on Gitea Actions.' . $suffix;

            Log::error('Playwright actions validation timed out', [
                'test_id' => $test->id,
                'repo' => $this->repoFullName,
                'error' => $error,
            ]);

            $testService->markTestFailed($test, $this->logFile, $error);
            return;
        }

        // Delegate API polling and log streaming logic to the runner service
        $result = $pipelineService->pollPlaywrightActions(
            $this->logFile,
            $this->repoFullName,
            $this->testBranch,
            $this->headSha,
            $test,
            $this->trackedRunId
        );

        // Standard event loop: If still running, pause and retry
        if ($result['status'] === 'pending') {
            // Re-queue this exact job instance to run again after 5 seconds
            $this->release(5);
            return;
        }

        if ($result['status'] === 'cancelled') {
            return; // Graceful termination
        }

        if ($result['status'] === 'error') {
            $error = (string) ($result['error'] ?? 'Playwright tests failed on Gitea Actions.');
            Log::error('Playwright actions validation failed', [
                'test_id' => $test->id,
                'repo' => $this->repoFullName,
                'error' => $error,
            ]);
            $testService->markTestFailed($test, $this->logFile, $error);
            return;
        }

        if ($result['status'] === 'completed') {
            $testService->markTestCompleted($test, $this->logFile);
            Log::info('Playwright generator completed successfully for ' . $test->name);
            return;
        }
    }
}
