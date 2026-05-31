<?php

namespace App\Jobs;

use App\Models\Test;
use App\Services\PlaywrightGeneratorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateTestJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    /**
     * Create a new job instance.
     */
    public function __construct(public readonly int $testId) {}

    /**
     * Execute the job.
     */
    public function handle(PlaywrightGeneratorService $generatorService): void
    {
        $test = Test::query()->find($this->testId);

        if (! $test) {
            return;
        }

        $generatorService->generateForTest($test);
    }
}
