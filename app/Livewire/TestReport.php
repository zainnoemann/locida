<?php

namespace App\Livewire;

use App\Models\Test;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Livewire\Component;

class TestReport extends Component
{
    public int $testId;
    public string $statusFilter = 'all';
    public string $keyword = '';
    public string $renderMode = 'full';
    private const DEFAULT_TEST_BRANCH = 'playwright';
    private const PRIMARY_REPORT_BASE_PATH = 'playwright/reports';
    private const PRIMARY_HTML_REPORT_FILE = 'index.html';
    private const PRIMARY_REPORT_JSON_FILE = 'report.json';
    private const PRIMARY_REPORT_ASSET_DIRS = [
        'data',
        'trace',
    ];

    public function mount(int $testId, string $renderMode = 'full')
    {
        $this->testId = $testId;
        $this->renderMode = $renderMode;
        $this->statusFilter = 'all';
        $this->keyword = '';
    }

    public function setStatusFilter(string $filter): void
    {
        if (in_array($filter, ['all', 'passed', 'failed', 'flaky', 'skipped'], true)) {
            $this->statusFilter = $filter;
        }
    }

    public function updatedKeyword(): void
    {
        // No-op, just for wire:model
    }

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
            fn(): array => $this->buildReportSummary($test)
        );
    }

    private function buildReportSummary(Test $test): array
    {
        $apiUrl = rtrim((string) config('services.gitea.url'), '/');
        $apiToken = (string) config('services.gitea.token');
        if ($apiUrl === '' || $apiToken === '') {
            return [
                'available' => false,
                'message' => 'Gitea API is not configured.',
            ];
        }
        [$owner, $repo] = $this->parseRepoFullName($test->repo_name ?? '');
        $testBranch = $this->resolveTestBranch($test);
        if ($owner === null || $repo === null) {
            return [
                'available' => false,
                'message' => 'Invalid repository name format. Expected owner/repo.',
                'htmlReportUrl' => null,
            ];
        }
        $htmlReportUrl = $this->cachePrimaryHtmlReportIndexAndGetUrl($test, $apiUrl, $apiToken, $owner, $repo, $testBranch);

        $json = $this->fetchReportJson($apiUrl, $apiToken, $owner, $repo, $testBranch);
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

    private function parseRepoFullName(string $fullName): array
    {
        $parts = explode('/', trim($fullName), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return [null, null];
        }
        return [$parts[0], $parts[1]];
    }

    private function resolveTestBranch(Test $test): string
    {
        $branch = trim((string) ($test->test_branch ?? ''));

        return $branch !== '' ? $branch : self::DEFAULT_TEST_BRANCH;
    }

    private function fetchReportJson(string $apiUrl, string $apiToken, string $owner, string $repo, string $testBranch): ?array
    {
        $candidatePaths = [
            $this->primaryReportPath(self::PRIMARY_REPORT_JSON_FILE),
            // backward compatibility with older generated workflows/branches
            'playwright/playwright-report/report.json',
            'playwright-report/report.json',
            'report.json',
        ];
        foreach ($candidatePaths as $path) {
            $decoded = $this->fetchRepositoryFileContent($apiUrl, $apiToken, $owner, $repo, $path, $testBranch);
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

    private function fetchRepositoryFileContent(string $apiUrl, string $apiToken, string $owner, string $repo, string $path, string $testBranch): ?string
    {
        $response = Http::withToken($apiToken)
            ->timeout(10)
            ->retry(1, 200)
            ->get("{$apiUrl}/repos/{$owner}/{$repo}/contents/{$path}", [
                'ref' => $testBranch,
            ]);

        if ($response->status() === 404 || ! $response->successful()) {
            return null;
        }

        $payload = $response->json();
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

    private function cachePrimaryHtmlReportIndexAndGetUrl(Test $test, string $apiUrl, string $apiToken, string $owner, string $repo, string $testBranch): ?string
    {
        $primaryHtmlPath = $this->primaryReportPath(self::PRIMARY_HTML_REPORT_FILE);
        $html = $this->fetchRepositoryFileContent(
            $apiUrl,
            $apiToken,
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

        foreach (self::PRIMARY_REPORT_ASSET_DIRS as $remoteAssetDir) {
            $relativeAssetDir = trim($remoteAssetDir, '/');
            $remoteAssetPath = $this->primaryReportPath($relativeAssetDir);

            $localAssetDir = $directory . '/' . $relativeAssetDir;
            if (File::exists($localAssetDir)) {
                File::deleteDirectory($localAssetDir);
            }

            $this->syncRepositoryDirectoryToLocal(
                $apiUrl,
                $apiToken,
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

    private function syncRepositoryDirectoryToLocal(
        string $apiUrl,
        string $apiToken,
        string $owner,
        string $repo,
        string $testBranch,
        string $remoteDirectoryPath,
        string $localDirectoryPath
    ): void {
        $response = Http::withToken($apiToken)
            ->timeout(10)
            ->retry(1, 200)
            ->get("{$apiUrl}/repos/{$owner}/{$repo}/contents/{$remoteDirectoryPath}", [
                'ref' => $testBranch,
            ]);

        if ($response->status() === 404 || ! $response->successful()) {
            return;
        }

        $entries = $response->json();
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
                    $apiUrl,
                    $apiToken,
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

            $content = $this->fetchRepositoryFileContent($apiUrl, $apiToken, $owner, $repo, $entryPath, $testBranch);
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
}
