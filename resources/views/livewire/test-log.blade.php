{{-- 
    Livewire Component View: Test Log
    Purpose: Renders the real-time test generation log and a visual timeline representing the stages of the generation process.
    The component polls every second to update the UI as new logs arrive.
--}}
<div wire:poll.1s>
    @php
    // Extract timeline data from the Livewire component property
    $timeline = $this->timeline;
    $stages = $timeline['stages'];
    $statusText = [
        'done' => 'Done',
        'active' => 'In Progress',
        'pending' => 'Pending',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ];
    // Define color mappings for timeline nodes, labels, and connecting lines based on stage status
    $nodeClasses = [
        'done' => 'bg-emerald-500',
        'active' => 'bg-amber-500',
        'pending' => 'bg-gray-300 dark:bg-gray-600',
        'failed' => 'bg-rose-500',
        'cancelled' => 'bg-slate-500',
    ];
    $labelClasses = [
        'done' => 'text-emerald-600 dark:text-emerald-400',
        'active' => 'text-amber-600 dark:text-amber-400',
        'pending' => 'text-gray-500 dark:text-gray-400',
        'failed' => 'text-rose-600 dark:text-rose-400',
        'cancelled' => 'text-slate-600 dark:text-slate-400',
    ];
    $lineClasses = [
        'done' => 'bg-emerald-500',
        'active' => 'bg-amber-500',
        'pending' => 'bg-gray-300 dark:bg-gray-700',
        'failed' => 'bg-rose-500',
        'cancelled' => 'bg-slate-500',
    ];
    @endphp

    @if ($viewType === 'both' || $viewType === 'timeline')
    <div class="w-full">
        @if ($timeline['isWaiting'])
        <div class="mb-4">
            <span class="text-sm text-gray-500 italic">Waiting for generator to start...</span>
        </div>
        @endif

        {{-- Scoped CSS for the horizontal timeline visualization --}}
        <style>
            .tl-container { width: 100%; height: 160px; position: relative; display: flex; align-items: flex-start; justify-content: space-between; padding: 2.5rem 6rem 0 6rem; }
            .tl-node-wrap { position: relative; display: flex; flex-direction: column; align-items: center; z-index: 10; width: 0; }
            
            .tl-line-segment { flex-grow: 1; height: 4px; transform: translateY(-2px); z-index: 0; margin-top: 2.5rem; }
            
            .tl-node { width: 2.5rem; height: 2.5rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); z-index: 20; transform: translateY(-50%); position: absolute; top: 2.5rem; }
            .tl-node-inner { width: 1rem; height: 1rem; border-radius: 50%; }
            .tl-node-pulse { position: absolute; width: 1rem; height: 1rem; border-radius: 50%; animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
            @keyframes pulse { 0% { opacity: 1; transform: scale(1); } 50% { opacity: .5; transform: scale(1.5); } 100% { opacity: 0; transform: scale(2); } }

            .tl-content { position: absolute; top: 5rem; width: 12rem; text-align: center; transform: translateX(-50%); left: 50%; }
            
            .tl-title { font-size: 1.125rem; font-weight: 700; line-height: 1.25; margin-bottom: 0.25rem; }
        </style>

        <div class="w-full overflow-hidden pb-4 pt-4">
            <div class="tl-container">
                @foreach ($stages as $index => $stage)
                @php
                    $status = $stage['status'];
                    $bgClass = $nodeClasses[$status] ?? 'bg-gray-300 dark:bg-gray-600';
                    $textClass = $labelClasses[$status] ?? 'text-gray-500 dark:text-gray-400';
                @endphp

                {{-- Render a timeline node --}}
                <div class="tl-node-wrap">
                    <div class="tl-node bg-white border-[3px] border-white dark:bg-gray-800 dark:border-gray-900">
                        <div class="tl-node-inner {{ $bgClass }}"></div>
                        {{-- Add pulsing animation if the stage is currently running --}}
                        @if($stage['status'] === 'active')
                            <div class="tl-node-pulse {{ $bgClass }}"></div>
                        @endif
                    </div>
                    <div class="tl-content">
                        <p class="tl-title {{ $textClass }}">{{ $stage['label'] }}</p>
                        @if(!empty($stage['duration']))
                            <p class="text-xs {{ $textClass }} opacity-80 font-medium">{{ $stage['duration'] }}</p>
                        @endif
                    </div>
                </div>

                {{-- Render the connecting line segment between nodes --}}
                @if (!$loop->last)
                    @php
                        $nextStage = $stages[$index + 1];
                        $lineClass = $lineClasses[$nextStage['status']] ?? 'bg-gray-300 dark:bg-gray-700';
                    @endphp
                    <div class="tl-line-segment {{ $lineClass }}"></div>
                @endif

                @endforeach
            </div>
        </div>
    </div>
    @endif

    @if ($viewType === 'both' || $viewType === 'stream')
    {{-- Terminal-style log output container --}}
    <div class="rounded-lg border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-3 sm:p-4 overflow-hidden">
        <div
            class="h-96 max-w-full overflow-x-auto overflow-y-auto rounded-md border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-black p-3"
            id="log-container-{{ $testId }}">
            <pre class="m-0 min-w-max whitespace-pre font-mono text-xs sm:text-sm leading-relaxed text-emerald-600 dark:text-emerald-300"><code class="block">{{ $this->formattedLogs }}</code></pre>
        </div>
    </div>

    {{-- Auto-scroll script: scrolls to bottom automatically if the user is already near the bottom --}}
    <script>
        document.addEventListener('livewire:initialized', () => {
            setInterval(() => {
                const container = document.getElementById('log-container-{{ $testId }}');
                if (container) {
                    const isNearBottom = container.scrollTop + container.clientHeight >= container.scrollHeight - 50;
                    if (isNearBottom) {
                        container.scrollTop = container.scrollHeight;
                    }
                }
            }, 1000);
        });
    </script>
    @endif
</div>