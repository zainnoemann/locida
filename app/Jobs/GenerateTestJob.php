<?php

namespace App\Jobs;

use App\Models\Test;
use App\Services\TestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Background job responsible for initiating the test generation sequence.
 * By offloading generation to the queue, the web interface remains responsive
 * while git clone, workspace setup, and pushing operations occur asynchronously.
 */
class GenerateTestJob implements ShouldQueue
{
    use Queueable;

    /**
     * Maximum execution time for the job in seconds.
     * 1800 seconds (30 minutes) provides ample time for large git clones and workflow setups.
     *
     * @var int
     */
    public int $timeout = 1800;

    /**
     * Create a new job instance.
     *
     * @param int $testId The primary key of the Test model being generated.
     */
    public function __construct(public readonly int $testId)
    {
    }

    /**
     * Execute the job.
     * Hands over execution to the TestService to process the actual generation lifecycle.
     *
     * @param TestService $testService Injected core service.
     */
    public function handle(TestService $testService): void
    {
        $test = Test::query()->find($this->testId);

        if (! $test) {
            return;
        }

        $testService->generateForTest($test);
    }
}
