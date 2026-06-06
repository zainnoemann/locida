<?php

namespace App\Services;

use App\Traits\PublishesSupportProject;
use Illuminate\Support\Facades\File;

/**
 * Service responsible for staging the Playwright test generation scripts.
 * It copies the necessary Node.js/Playwright scaffolding into the target repository workspace.
 */
class ScriptGeneratorService
{
    use PublishesSupportProject;

    /**
     * Prepares the script generator scaffolding inside the cloned repository workspace.
     * Copies the source files (package.json, tsconfig.json, src/) into the designated playwright/generator directory.
     *
     * @param string $outputDir The path to the working directory where the repository was cloned.
     */
    public function prepareGenerator(string $outputDir): void
    {
        $playwrightDir = $outputDir . '/playwright';
        if (!File::exists($playwrightDir)) {
            File::makeDirectory($playwrightDir, 0755, true);
        }

        // Publish the Node.js project needed to generate tests via Playwright
        $this->publishSupportProject(
            base_path('playwright/generator'),
            $playwrightDir . '/generator',
            ['src'],
            ['package.json', 'package-lock.json', 'tsconfig.json']
        );
    }
}
