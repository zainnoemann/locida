<?php

namespace App\Livewire;

use App\Models\Test;
use App\Services\GiteaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Livewire\Component;

/**
 * Livewire component responsible for fetching, parsing, and rendering Playwright test reports.
 * It interacts with the Gitea API to retrieve the `report.json` output and the static `index.html` report,
 * caching the results and mapping the tests into a structured visual format.
 */
class TestReport extends Component
{
    private const DEFAULT_TEST_BRANCH = 'playwright';
    private const PRIMARY_REPORT_BASE_PATH = 'playwright/generator/reports';
    private const PRIMARY_HTML_REPORT_FILE = 'index.html';
    private const PRIMARY_REPORT_JSON_FILE = 'report.json';

    /** Directories containing necessary assets for the HTML report. */
    private const PRIMARY_REPORT_ASSET_DIRS = [
        'data',
        'trace',
    ];
    /** The target test ID. */
    public int $testId;

    /** Filter state for UI (all, passed, failed, flaky, skipped). */
    public string $statusFilter = 'all';

    /** Search keyword for filtering test specs. */
    public string $keyword = '';

    /** UI render layout mode. */
    public string $renderMode = 'full';

    /**
     * Component initialization.
     */
    public function mount(int $testId, string $renderMode = 'full')
    {
        $this->testId = $testId;
        $this->renderMode = $renderMode;
        $this->statusFilter = 'all';
        $this->keyword = '';
    }

    /**
     * Updates the status filter to control which specs are shown.
     */
    public function setStatusFilter(string $filter): void
    {
        if (in_array($filter, ['all', 'passed', 'failed', 'flaky', 'skipped'], true)) {
            $this->statusFilter = $filter;
        }
    }

    /**
     * Triggered automatically by Livewire when the keyword model updates.
     */
    public function updatedKeyword(): void
    {
        // No-op, just for wire:model
    }

    /**
     * Computed property that orchestrates the report fetching and parsing.
     * Caches the final array for 15 seconds to prevent excessive Gitea API spam during page reloads.
     *
     * @return array Structured report summary.
     */
    public function getReportProperty(): array
    {
        $test = Test::query()->select(['id', 'repo_name', 'repo_url', 'test_branch'])->find($this->testId);
        if ($test === null) {
            return [
                'available' => false,
                'message' => 'Test not found.',
            ];
        }
        return Cache::remember(
            "playwright-report-summary:{$this->testId}",
            now()->addSeconds(15),
            fn (): array => $this->buildReportSummary($test)
        );
    }

    /**
     * Filters the aggregated specs array based on current UI keyword and status filter state.
     *
     * @param array $specs
     * @return array
     */
    public function filterSpecs(array $specs): array
    {
        $keyword = strtolower(trim($this->keyword));
        return array_values(array_filter($specs, function (array $spec) use ($keyword): bool {
            $status = strtolower((string) ($spec['status'] ?? 'unknown'));

            $statusMatch = match ($this->statusFilter) {
                'passed' => in_array($status, ['passed', 'expected'], true),
                'failed' => in_array($status, ['failed', 'timedout', 'interrupted'], true),
                'flaky' => $status === 'flaky',
                'skipped' => $status === 'skipped',
                default => true,
            };

            if (! $statusMatch) {
                return false;
            }

            if ($keyword === '') {
                return true;
            }

            $haystack = strtolower(trim(implode(' ', [
                (string) ($spec['title'] ?? ''),
                (string) ($spec['status'] ?? ''),
                (string) ($spec['message'] ?? ''),
                (string) ($spec['group'] ?? ''),
            ])));

            return str_contains($haystack, $keyword);
        }));
    }

    public function render()
    {
        return view('livewire.test-report');
    }

    /**
     * Core logic to fetch the report JSON from Gitea, sync the HTML files locally,
     * and extract standard statistics.
     *
     * @param Test $test
     * @return array
     */
    private function buildReportSummary(Test $test): array
    {
        [$owner, $repo] = $this->parseRepoFullName($test->repo_name ?? '');
        $testBranch = $this->resolveTestBranch($test);
        if ($owner === null || $repo === null) {
            return [
                'available' => false,
                'message' => 'Invalid repository name format. Expected owner/repo.',
                'htmlReportUrl' => null,
            ];
        }

        // Sync static HTML report files for local viewing
        $htmlReportUrl = $this->cachePrimaryHtmlReportIndexAndGetUrl($test, $owner, $repo, $testBranch);

        $json = $this->fetchReportJson($owner, $repo, $testBranch);
        if (! is_array($json)) {
            $primaryReportPath = $this->primaryReportPath(self::PRIMARY_REPORT_JSON_FILE);
            return [
                'available' => false,
                'message' => 'Report not found yet at ' . $primaryReportPath . ' in branch ' . $testBranch . '.',
                'htmlReportUrl' => $htmlReportUrl,
            ];
        }

        $stats = is_array($json['stats'] ?? null) ? $json['stats'] : [];
        $expected = (int) ($stats['expected'] ?? 0);
        $unexpected = (int) ($stats['unexpected'] ?? 0);
        $flaky = (int) ($stats['flaky'] ?? 0);
        $skipped = (int) ($stats['skipped'] ?? 0);

        return [
            'available' => true,
            'branch' => $testBranch,
            'generatedAt' => $stats['startTime'] ?? null,
            'durationMs' => (int) ($stats['duration'] ?? 0),
            'htmlReportUrl' => $htmlReportUrl,
            'stats' => [
                'expected' => $expected,
                'unexpected' => $unexpected,
                'flaky' => $flaky,
                'skipped' => $skipped,
                'total' => $expected + $unexpected + $flaky + $skipped,
            ],
            'specs' => $this->extractSpecs($json),
        ];
    }

    /**
     * Splits a full repository name into owner and repo strings.
     */
    private function parseRepoFullName(string $fullName): array
    {
        $parts = explode('/', trim($fullName), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return [null, null];
        }
        return [$parts[0], $parts[1]];
    }

    /**
     * Determines which branch to check for reports.
     */
    private function resolveTestBranch(Test $test): string
    {
        $branch = trim((string) ($test->test_branch ?? ''));

        return $branch !== '' ? $branch : self::DEFAULT_TEST_BRANCH;
    }

    /**
     * Attempts to find the `report.json` file in multiple known paths.
     * This ensures backward compatibility with older generated projects.
     *
     * @return array|null Decoded JSON report.
     */
    private function fetchReportJson(string $owner, string $repo, string $testBranch): ?array
    {
        $candidatePaths = [
            $this->primaryReportPath(self::PRIMARY_REPORT_JSON_FILE),
            // backward compatibility with older generated workflows/branches
            'playwright/reports/report.json',
            'playwright/playwright-report/report.json',
            'playwright-report/report.json',
            'report.json',
        ];

        foreach ($candidatePaths as $path) {
            $decoded = $this->fetchRepositoryFileContent($owner, $repo, $path, $testBranch);
            if ($decoded === null) {
                continue;
            }

            $json = json_decode($decoded, true);
            if (is_array($json)) {
                return $json;
            }
        }

        return null;
    }

    /**
     * Requests raw file content from Gitea and decodes the Base64 response.
     */
    private function fetchRepositoryFileContent(string $owner, string $repo, string $path, string $testBranch): ?string
    {
        $payload = app(GiteaService::class)->getRepositoryContent($owner, $repo, $path, $testBranch);

        if ($payload === null) {
            return null;
        }
        $base64 = is_array($payload) ? (string) ($payload['content'] ?? '') : '';
        if ($base64 === '') {
            return null;
        }

        $decoded = base64_decode(str_replace(["\n", "\r"], '', $base64), true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        return $decoded;
    }

    /**
     * Downloads the main static HTML report and its supporting trace/data assets.
     * Saves them locally into storage to be served by the application securely.
     *
     * @return string|null Temporary signed URL to view the downloaded HTML report.
     */
    private function cachePrimaryHtmlReportIndexAndGetUrl(Test $test, string $owner, string $repo, string $testBranch): ?string
    {
        $primaryHtmlPath = $this->primaryReportPath(self::PRIMARY_HTML_REPORT_FILE);
        $html = $this->fetchRepositoryFileContent(
            $owner,
            $repo,
            $primaryHtmlPath,
            $testBranch
        );

        if ($html === null || trim($html) === '') {
            return $this->buildStoredHtmlReportUrlIfExists($test->id);
        }

        $directory = $this->storedHtmlReportBaseDirectory($test->id);
        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
        File::put($this->storedHtmlReportFilePath($test->id), $html);

        // Fetch dependent assets like ZIP traces and JSON data chunks required by Playwright UI.
        foreach (self::PRIMARY_REPORT_ASSET_DIRS as $remoteAssetDir) {
            $relativeAssetDir = trim($remoteAssetDir, '/');
            $remoteAssetPath = $this->primaryReportPath($relativeAssetDir);

            $localAssetDir = $directory . '/' . $relativeAssetDir;
            if (File::exists($localAssetDir)) {
                File::deleteDirectory($localAssetDir);
            }

            $this->syncRepositoryDirectoryToLocal(
                $owner,
                $repo,
                $testBranch,
                $remoteAssetPath,
                $localAssetDir
            );
        }

        return URL::temporarySignedRoute('playwright-reports.index', now()->addMinutes(30), [
            'test' => $test->id,
        ]);
    }

    /**
     * Recursively walks a Gitea directory via API and mirrors it to the local filesystem.
     */
    private function syncRepositoryDirectoryToLocal(
        string $owner,
        string $repo,
        string $testBranch,
        string $remoteDirectoryPath,
        string $localDirectoryPath
    ): void {
        $entries = app(GiteaService::class)->getRepositoryContent($owner, $repo, $remoteDirectoryPath, $testBranch);

        if ($entries === null) {
            return;
        }
        if (! is_array($entries)) {
            return;
        }

        if (! File::exists($localDirectoryPath)) {
            File::makeDirectory($localDirectoryPath, 0755, true);
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $type = strtolower((string) ($entry['type'] ?? ''));
            $entryPath = (string) ($entry['path'] ?? '');
            $entryName = (string) ($entry['name'] ?? '');
            if ($entryPath === '' || $entryName === '') {
                continue;
            }

            if ($type === 'dir') {
                $this->syncRepositoryDirectoryToLocal(
                    $owner,
                    $repo,
                    $testBranch,
                    $entryPath,
                    $localDirectoryPath . '/' . $entryName
                );
                continue;
            }

            if ($type !== 'file') {
                continue;
            }

            $content = $this->fetchRepositoryFileContent($owner, $repo, $entryPath, $testBranch);
            if ($content === null) {
                continue;
            }

            File::put($localDirectoryPath . '/' . $entryName, $content);
        }
    }

    private function storedHtmlReportBaseDirectory(int $testId): string
    {
        return storage_path("app/playwright/test-{$testId}/reports");
    }

    private function storedHtmlReportDirectory(int $testId): string
    {
        return $this->storedHtmlReportBaseDirectory($testId);
    }

    private function storedHtmlReportFilePath(int $testId): string
    {
        return $this->storedHtmlReportDirectory($testId) . '/index.html';
    }

    private function primaryReportPath(string $relativePath): string
    {
        $basePath = trim(self::PRIMARY_REPORT_BASE_PATH, '/');
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '') {
            return $basePath;
        }

        return $basePath . '/' . $relativePath;
    }

    private function buildStoredHtmlReportUrlIfExists(int $testId): ?string
    {
        if (! File::exists($this->storedHtmlReportFilePath($testId))) {
            return null;
        }

        return URL::temporarySignedRoute('playwright-reports.index', now()->addMinutes(30), [
            'test' => $testId,
        ]);
    }

    /**
     * Recursively flattens the nested 'suites' tree inside the Playwright report.json.
     * Extracts individual specs, determining their final status and nested group names.
     *
     * @param array $report The decoded JSON report.
     * @return array Flat array of parsed spec items.
     */
    private function extractSpecs(array $report): array
    {
        $items = [];
        $suites = $report['suites'] ?? [];

        $walk = function (array $suite, string $prefix = '', ?string $parentGroup = null, int $depth = 0) use (&$walk, &$items): void {
            $currentTitle = (string) ($suite['title'] ?? '');
            if ($depth === 0) {
                $suiteTitle = '';
            } else {
                $suiteTitle = trim(($prefix !== '' ? $prefix . ' > ' : '') . $currentTitle);
            }

            $currentGroup = $parentGroup ?? ($currentTitle !== '' ? $currentTitle : 'Unnamed Group');
            $specs = is_array($suite['specs'] ?? null) ? $suite['specs'] : [];

            foreach ($specs as $spec) {
                if (! is_array($spec)) {
                    continue;
                }

                $file = (string) ($spec['file'] ?? '');
                $groupName = $file !== '' ? basename($file) : $currentGroup;
                $tests = is_array($spec['tests'] ?? null) ? $spec['tests'] : [];

                $errorMessage = null;
                $durationMs = 0;
                $hasPassed = false;
                $hasFailed = false;
                $hasSkipped = false;
                $hasFlaky = false;

                // Aggregate statuses across all retries/shards
                foreach ($tests as $test) {
                    if (! is_array($test)) {
                        continue;
                    }

                    $testOutcome = strtolower((string) ($test['outcome'] ?? ''));
                    if ($testOutcome === 'flaky') {
                        $hasFlaky = true;
                    }
                    if ($testOutcome === 'skipped') {
                        $hasSkipped = true;
                    }

                    $testStatus = strtolower((string) ($test['status'] ?? ''));
                    if ($testStatus === 'flaky') {
                        $hasFlaky = true;
                    }
                    if ($testStatus === 'skipped') {
                        $hasSkipped = true;
                    }

                    $results = is_array($test['results'] ?? null) ? $test['results'] : [];
                    foreach ($results as $result) {
                        if (! is_array($result)) {
                            continue;
                        }

                        $currentStatus = strtolower((string) ($result['status'] ?? 'unknown'));
                        if ($currentStatus === 'passed') {
                            $hasPassed = true;
                        }
                        if (in_array($currentStatus, ['failed', 'timedout', 'interrupted'], true)) {
                            $hasFailed = true;
                        }
                        if ($currentStatus === 'skipped') {
                            $hasSkipped = true;
                        }

                        $durationMs += (int) ($result['duration'] ?? 0);
                        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];

                        if (! empty($errors) && is_array($errors[0] ?? null)) {
                            $errorMessage = (string) (($errors[0]['message'] ?? null) ?? '');
                        }
                    }
                }

                $status = 'unknown';
                if ($hasFlaky || ($hasPassed && $hasFailed)) {
                    $status = 'flaky';
                } elseif ($hasFailed) {
                    $status = 'failed';
                } elseif ($hasPassed) {
                    $status = 'passed';
                } elseif ($hasSkipped) {
                    $status = 'skipped';
                }

                $title = trim(($suiteTitle !== '' ? $suiteTitle . ' > ' : '') . (string) ($spec['title'] ?? 'Unnamed spec'));

                $items[] = [
                    'group'      => $groupName,
                    'title'      => $title,
                    'status'     => $status,
                    'durationMs' => $durationMs,
                    'message'    => $errorMessage !== '' ? $errorMessage : null,
                ];
            }

            $childSuites = is_array($suite['suites'] ?? null) ? $suite['suites'] : [];
            foreach ($childSuites as $child) {
                if (is_array($child)) {
                    $walk($child, $suiteTitle, $currentGroup, $depth + 1);
                }
            }
        };

        if (is_array($suites)) {
            foreach ($suites as $suite) {
                if (is_array($suite)) {
                    $walk($suite, '', null, 0);
                }
            }
        }

        return $items;
    }
}
