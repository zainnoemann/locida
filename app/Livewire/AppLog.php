<?php

namespace App\Livewire;

use Illuminate\Support\Facades\File;
use Livewire\Component;

class AppLog extends Component
{
    public int $appId;

    public string $viewType = 'both';

    /**
     * @var array<int, array{key: string, label: string, marker: string}>
     */
    private const TIMELINE_STAGES = [
        ['key' => 'start', 'label' => 'Start', 'marker' => 'Starting generator process'],
        ['key' => 'clone', 'label' => 'Clone Source', 'marker' => 'Cloning source'],
        ['key' => 'generate', 'label' => 'Generate Setup', 'marker' => 'Running App Generator'],
        ['key' => 'push', 'label' => 'Push Branch', 'marker' => 'Committing and pushing generated setup to Gitea'],
        ['key' => 'done', 'label' => 'Completed', 'marker' => 'Done.'],
    ];

    /**
     * @var array<int, string>
     */
    private const FAILURE_MARKERS = [
        'Git Clone Error',
        'App Generator Error',
        'Git Push Error',
        'Exception:',
    ];

    public function mount(int $appId, string $viewType = 'both'): void
    {
        $this->appId = $appId;
        $this->viewType = $viewType;
    }

    public function getLogsProperty(): string
    {
        $logFile = storage_path("app/generator-logs/app-{$this->appId}.log");

        if (! File::exists($logFile)) {
            return "Waiting for generator to start...\n";
        }

        return File::get($logFile);
    }

    public function getFormattedLogsProperty(): string
    {
        $logs = str_replace(["\r\n", "\r"], "\n", $this->logs);
        $logs = preg_replace('/[ \t]+$/m', '', $logs) ?? $logs;
        $logs = preg_replace("/\n{3,}/", "\n\n", $logs) ?? $logs;

        return rtrim($logs, "\n")."\n";
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
                'stages' => array_map(static fn (array $stage): array => [
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

        foreach (self::TIMELINE_STAGES as $stage) {
            $status = 'pending';
            $stagePosition = $stagePositions[$stage['key']] ?? null;

            if ($completedStageKey !== null) {
                $status = 'done';
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

        if ($failurePosition !== null && ! $failedAttached) {
            $lastStageIndex = count($stages) - 1;
            if ($lastStageIndex >= 0) {
                $stages[$lastStageIndex]['status'] = 'failed';
            }
        }

        return [
            'isWaiting' => false,
            'stages' => $stages,
        ];
    }

    public function render()
    {
        return view('livewire.app-log');
    }
}
