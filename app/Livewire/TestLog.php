<?php

namespace App\Livewire;

use Illuminate\Support\Facades\File;
use Livewire\Component;

class TestLog extends Component
{
    public int $testId;
    public string $viewType = 'both';

    /**
     * @var array<int, array{key: string, label: string, marker: string}>
     */
    private const TIMELINE_STAGES = [
        ['key' => 'start', 'label' => 'Start', 'marker' => 'Starting generator process'],
        ['key' => 'init', 'label' => 'Test Initialization', 'marker' => 'Cloning source'],
        ['key' => 'generate', 'label' => 'Test Generation', 'marker' => 'Playwright Test Generator'],
        ['key' => 'execute', 'label' => 'Test Execution', 'marker' => 'tests using '],
        ['key' => 'report', 'label' => 'Test Reporting', 'marker' => 'Playwright report available'],
        ['key' => 'done', 'label' => 'Completed', 'marker' => 'Done.'],
    ];

    /**
     * @var array<int, string>
     */
    private const FAILURE_MARKERS = [
        'Git Clone Error',
        'Error running [',
        'Error:',
        'Exception:',
    ];

    /**
     * @var array<int, string>
     */
    private const CANCELLATION_MARKERS = [
        'Generation cancelled by user.',
    ];

    public function mount(int $testId, string $viewType = 'both')
    {
        $this->testId = $testId;
        $this->viewType = $viewType;
    }

    public function getLogsProperty(): string
    {
        $logFile = storage_path("app/generator-logs/test-{$this->testId}.log");

        if (!File::exists($logFile)) {
            return "Waiting for generator to start...\n";
        }

        return File::get($logFile);
    }

    public function getFormattedLogsProperty(): string
    {
        $logs = str_replace(["\r\n", "\r"], "\n", $this->logs);

        // Keep logs readable by removing trailing spaces and excessive blank lines.
        $logs = preg_replace('/[ \t]+$/m', '', $logs) ?? $logs;
        $logs = preg_replace("/\n{3,}/", "\n\n", $logs) ?? $logs;

        return rtrim($logs, "\n") . "\n";
    }

    /**
     * @return array{isWaiting: bool, stages: array<int, array{key: string, label: string, status: string}>}
     */
    public function getTimelineProperty(): array
    {
        $logs = $this->logs;
        $isWaiting = str_starts_with($logs, 'Waiting for generator to start');

        if ($isWaiting) {
            return [
                'isWaiting' => true,
                'stages' => array_map(static fn(array $stage): array => [
                    'key' => $stage['key'],
                    'label' => $stage['label'],
                    'status' => 'pending',
                ], self::TIMELINE_STAGES),
            ];
        }

        $stagePositions = [];
        foreach (self::TIMELINE_STAGES as $stage) {
            $position = strpos($logs, $stage['marker']);
            if ($position !== false) {
                $stagePositions[$stage['key']] = $position;
            }
        }

        $failurePosition = null;
        foreach (self::FAILURE_MARKERS as $marker) {
            $position = strpos($logs, $marker);
            if ($position === false) {
                continue;
            }

            $failurePosition = $failurePosition === null
                ? $position
                : min($failurePosition, $position);
        }

        $cancellationPosition = null;
        foreach (self::CANCELLATION_MARKERS as $marker) {
            $position = strpos($logs, $marker);
            if ($position === false) {
                continue;
            }

            $cancellationPosition = $cancellationPosition === null
                ? $position
                : min($cancellationPosition, $position);
        }

        $activeStageKey = null;
        $completedStageKey = null;

        if (isset($stagePositions['done'])) {
            $completedStageKey = 'done';
        } else {
            foreach (array_reverse(self::TIMELINE_STAGES) as $stage) {
                if (isset($stagePositions[$stage['key']])) {
                    $activeStageKey = $stage['key'];
                    break;
                }
            }

            if ($activeStageKey === null) {
                $activeStageKey = 'start';
            }
        }

        $stages = [];
        $failedAttached = false;

        foreach (self::TIMELINE_STAGES as $index => $stage) {
            $status = 'pending';
            $stagePosition = $stagePositions[$stage['key']] ?? null;

            if ($completedStageKey !== null) {
                $status = 'done';
            } elseif ($cancellationPosition !== null) {
                if ($stagePosition !== null && $stagePosition < $cancellationPosition) {
                    $status = 'done';
                }

                if ($stage['key'] === $activeStageKey) {
                    $status = 'cancelled';
                    $failedAttached = true;
                }
            } elseif ($failurePosition !== null) {
                if ($stagePosition !== null && $stagePosition < $failurePosition) {
                    $status = 'done';
                }

                if ($stage['key'] === $activeStageKey) {
                    $status = 'failed';
                    $failedAttached = true;
                }
            } else {
                if ($stage['key'] === $activeStageKey) {
                    $status = 'active';
                } elseif ($stagePosition !== null) {
                    $status = 'done';
                }
            }

            $stages[] = [
                'key' => $stage['key'],
                'label' => $stage['label'],
                'status' => $status,
            ];
        }

        if (($failurePosition !== null || $cancellationPosition !== null) && ! $failedAttached) {
            $lastStageIndex = count($stages) - 1;
            if ($lastStageIndex >= 0) {
                $stages[$lastStageIndex]['status'] = $cancellationPosition !== null ? 'cancelled' : 'failed';
            }
        }

        $stageTimestamps = [];
        foreach (self::TIMELINE_STAGES as $s) {
            $marker = preg_quote($s['marker'], '/');
            $pattern = '/(?:\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]|(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d+)?Z)[^\n]*?' . $marker . '/';
            if (preg_match($pattern, $logs, $matches)) {
                if (!empty($matches[1])) {
                    $stageTimestamps[$s['key']] = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $matches[1]);
                } elseif (!empty($matches[2])) {
                    $stageTimestamps[$s['key']] = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', str_replace('T', ' ', $matches[2]));
                }
            }
        }

        for ($i = 0; $i < count($stages); $i++) {
            $currentKey = $stages[$i]['key'];
            $nextKey = $stages[$i + 1]['key'] ?? null;
            $start = $stageTimestamps[$currentKey] ?? null;
            $end = $stageTimestamps[$nextKey] ?? null;
            $durationStr = '';
            if ($start) {
                if ($end) {
                    $duration = max(0, $start->diffInSeconds($end));
                } elseif ($stages[$i]['status'] === 'active') {
                    $duration = max(0, $start->diffInSeconds(now()));
                } elseif ($stages[$i]['status'] === 'failed') {
                    $logFile = storage_path("app/generator-logs/test-{$this->testId}.log");
                    if (File::exists($logFile)) {
                        $duration = max(0, $start->diffInSeconds(\Carbon\Carbon::createFromTimestamp(File::lastModified($logFile))));
                    } else {
                        $duration = null;
                    }
                } else {
                    $duration = null;
                }

                if ($duration !== null) {
                    if ($duration >= 60) {
                        $m = floor($duration / 60);
                        $s = $duration % 60;
                        $durationStr = "{$m}m {$s}s";
                    } else {
                        $durationStr = "{$duration}s";
                    }
                }
            }
            $stages[$i]['duration'] = $durationStr;
        }

        $filteredStages = array_values(array_filter($stages, function($s) {
            return !in_array($s['key'], ['start', 'done']);
        }));

        return [
            'isWaiting' => false,
            'stages' => $filteredStages,
        ];
    }

    public function render()
    {
        return view('livewire.test-log');
    }
}
