<?php

namespace App\Services;

use App\Traits\PublishesSupportProject;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Service responsible for managing the web crawling logic.
 * Handles target URL validation, network normalization for Docker environments,
 * and staging the crawler scaffolding into the target repository.
 */
class WebCrawlerService
{
    use PublishesSupportProject;



    /**
     * Prepares the web crawler scaffolding inside the cloned repository workspace.
     * Copies the source files into the designated playwright/crawler directory.
     *
     * @param string $outputDir The path to the working directory where the repository was cloned.
     */
    public function prepareCrawler(string $outputDir): void
    {
        $playwrightDir = $outputDir . '/playwright';
        if (!File::exists($playwrightDir)) {
            File::makeDirectory($playwrightDir, 0755, true);
        }

        $this->publishSupportProject(
            base_path('playwright/crawler'),
            $playwrightDir . '/crawler',
            ['src'],
            ['package.json', 'package-lock.json', 'tsconfig.json', '.env.example']
        );
    }
}
