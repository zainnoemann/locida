<?php

namespace App\Livewire;

use App\Models\Test;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class TestReport extends Component
{
    public int $testId;
    public string $statusFilter = 'all';
    public string $keyword = '';
    private const REPORT_BRANCH = 'playwright-report';

    public function mount(int $testId)
    {
        $this->testId = $testId;
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
        $test = Test::query()->select(['id', 'repo_name'])->find($this->testId);
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
        $apiUrl = rtrim((string) env('GITEA_API_URL', ''), '/');
        $apiToken = (string) env('GITEA_API_TOKEN', '');
        if ($apiUrl === '' || $apiToken === '') {
            return [
                'available' => false,
                'message' => 'Gitea API is not configured.',
            ];
        }
        [$owner, $repo] = $this->parseRepoFullName($test->repo_name ?? '');
        if ($owner === null || $repo === null) {
            return [
                'available' => false,
                'message' => 'Invalid repository name format. Expected owner/repo.',
            ];
        }
        $json = $this->fetchReportJson($apiUrl, $apiToken, $owner, $repo);
        if (! is_array($json)) {
            return [
                'available' => false,
                'message' => 'report.json not found yet in branch ' . self::REPORT_BRANCH . '.',
            ];
        }
        $stats = is_array($json['stats'] ?? null) ? $json['stats'] : [];
        $expected = (int) ($stats['expected'] ?? 0);
        $unexpected = (int) ($stats['unexpected'] ?? 0);
        $flaky = (int) ($stats['flaky'] ?? 0);
        $skipped = (int) ($stats['skipped'] ?? 0);
        return [
            'available' => true,
            'branch' => self::REPORT_BRANCH,
            'generatedAt' => $stats['startTime'] ?? null,
            'durationMs' => (int) ($stats['duration'] ?? 0),
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

    private function fetchReportJson(string $apiUrl, string $apiToken, string $owner, string $repo): ?array
    {
        $candidatePaths = ['report.json', 'playwright-report/report.json'];
        foreach ($candidatePaths as $path) {
            $response = Http::withToken($apiToken)
                ->timeout(10)
                ->retry(1, 200)
                ->get("{$apiUrl}/repos/{$owner}/{$repo}/contents/{$path}", [
                    'ref' => self::REPORT_BRANCH,
                ]);
            if ($response->status() === 404) {
                continue;
            }
            if (! $response->successful()) {
                continue;
            }
            $payload = $response->json();
            $base64 = is_array($payload) ? (string) ($payload['content'] ?? '') : '';
            if ($base64 === '') {
                continue;
            }
            $decoded = base64_decode(str_replace(["\n", "\r"], '', $base64), true);
            if ($decoded === false || $decoded === '') {
                continue;
            }
            $json = json_decode($decoded, true);
            if (is_array($json)) {
                return $json;
            }
        }
        return null;
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
