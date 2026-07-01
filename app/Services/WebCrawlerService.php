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
     * Normalizes a host string to be resolvable within a Docker environment.
     * Maps common localhost equivalents to the Docker internal host alias.
     *
     * @param string $host The original host (e.g., 'localhost', '127.0.0.1').
     * @return string The normalized host (e.g., 'host.docker.internal' or the original host).
     */
    public function normalizeRuntimeHost(string $host): string
    {
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
            return 'host.docker.internal';
        }

        return $host;
    }

    /**
     * Parses and resolves the final target URL the crawler should access.
     * Applies Docker host normalization and ensures a valid HTTP/HTTPS scheme.
     *
     * @param string|null $testUrl The raw URL submitted for testing.
     * @return string The fully resolved and normalized URL.
     */
    public function resolveTargetUrl(?string $testUrl): string
    {
        $candidate = trim((string) $testUrl);

        if ($candidate === '') {
            $candidate = (string) config('app.url', 'http://localhost:8000');
        }

        if (! str_starts_with($candidate, 'http://') && ! str_starts_with($candidate, 'https://')) {
            $candidate = 'http://' . ltrim($candidate, '/');
        }

        $parsed = parse_url($candidate);
        if ($parsed !== false && ! empty($parsed['host'])) {
            $scheme = $parsed['scheme'] ?? 'http';
            $host = $this->normalizeRuntimeHost($parsed['host']);
            $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
            $path = $parsed['path'] ?? '';
            $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
            $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

            $candidate = $scheme . '://' . $host . $port . $path . $query . $fragment;
        }

        return rtrim($candidate, '/');
    }



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
