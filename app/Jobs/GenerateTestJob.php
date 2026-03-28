<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateTestJob implements ShouldQueue
{
    use Queueable;

    public $test;

    /**
     * Create a new job instance.
     */
    public function __construct(\App\Models\Test $test)
    {
        $this->test = $test;
    }

    /**
     * Execute the job.
     */
    public function handle(\App\Services\PlaywrightGeneratorService $generatorService): void
    {
        $generatorService->generateForTest($this->test);
    }
}
