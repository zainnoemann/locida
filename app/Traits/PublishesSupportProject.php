<?php

namespace App\Traits;

use Illuminate\Support\Facades\File;

/**
 * Reusable trait for services that need to scaffold files and directories.
 * Centralizes the logic for recursively copying templates into generated workspaces.
 */
trait PublishesSupportProject
{
    /**
     * Copies selected directories and files from a source template to a target destination.
     * Recreates the target directory structure if missing.
     *
     * @param string $sourceDir The path containing template files.
     * @param string $targetDir The destination path in the repository workspace.
     * @param array $directories Array of directory names to copy recursively.
     * @param array $files Array of individual file names to copy.
     */
    protected function publishSupportProject(string $sourceDir, string $targetDir, array $directories, array $files): void
    {
        File::ensureDirectoryExists($targetDir);

        foreach ($directories as $dir) {
            if (File::exists("$sourceDir/$dir")) {
                File::copyDirectory("$sourceDir/$dir", "$targetDir/$dir");
            }
        }

        foreach ($files as $file) {
            if (File::exists("$sourceDir/$file")) {
                File::copy("$sourceDir/$file", "$targetDir/$file");
            }
        }
    }
}
