<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAppJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public $app;

    /**
     * Create a new job instance.
     */
    public function __construct(\App\Models\App $app)
    {
        $this->app = $app;
    }

    /**
     * Execute the job.
     */
    public function handle(\App\Services\AppGeneratorService $generatorService): void
    {
        $generatorService->generateForApp($this->app);
    }
}
