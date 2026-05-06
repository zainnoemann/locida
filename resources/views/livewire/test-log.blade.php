<div wire:poll.1s>
    @php
    $timeline = $this->timeline;
    $stages = $timeline['stages'];
    $statusText = [
    'done' => 'Done',
    'active' => 'In Progress',
    'pending' => 'Pending',
    'failed' => 'Failed',
    'cancelled' => 'Cancelled',
    ];
    $nodeClasses = [
    'done' => 'border-emerald-500 bg-emerald-500',
    'active' => 'border-amber-500 bg-amber-500 animate-pulse',
    'pending' => 'border-gray-400 bg-white',
    'failed' => 'border-rose-500 bg-rose-500',
    'cancelled' => 'border-slate-500 bg-slate-500',
    ];
    $labelClasses = [
    'done' => 'text-emerald-700 dark:text-emerald-300',
    'active' => 'text-amber-700 dark:text-amber-300',
    'pending' => 'text-gray-600 dark:text-gray-400',
    'failed' => 'text-rose-700 dark:text-rose-300',
    'cancelled' => 'text-slate-600 dark:text-slate-300',
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

        <style>
            .tl-container { width: 100%; height: 160px; position: relative; display: flex; align-items: flex-start; justify-content: space-between; padding: 2.5rem 6rem 0 6rem; }
            .tl-node-wrap { position: relative; display: flex; flex-direction: column; align-items: center; z-index: 10; width: 0; }
            
            .tl-line-segment { flex-grow: 1; height: 4px; transform: translateY(-2px); z-index: 0; margin-top: 2.5rem; }
            
            .tl-node { width: 2.5rem; height: 2.5rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 3px solid #111827; background-color: #1f2937; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); z-index: 20; transform: translateY(-50%); position: absolute; top: 2.5rem; }
            .tl-node-inner { width: 1rem; height: 1rem; border-radius: 50%; }
            .tl-node-pulse { position: absolute; width: 1rem; height: 1rem; border-radius: 50%; animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
            @keyframes pulse { 0% { opacity: 1; transform: scale(1); } 50% { opacity: .5; transform: scale(1.5); } 100% { opacity: 0; transform: scale(2); } }

            .tl-content { position: absolute; top: 5rem; width: 12rem; text-align: center; transform: translateX(-50%); left: 50%; }
            
            .tl-title { font-size: 1.125rem; font-weight: 700; line-height: 1.25; margin-bottom: 0.25rem; }

            .bg-primary-c { background-color: #f59e0b; } .text-primary-c { color: #f59e0b; }
            .bg-gray-c { background-color: #4b5563; } .text-gray-c { color: #9ca3af; }
            .bg-rose-c { background-color: #e11d48; } .text-rose-c { color: #fb7185; }
        </style>

        <div class="w-full overflow-hidden pb-4 pt-4">
            <div class="tl-container">
                @foreach ($stages as $index => $stage)
                @php
                    $colorName = 'primary';
                    if ($stage['status'] === 'pending') {
                        $colorName = 'gray';
                    } elseif ($stage['status'] === 'failed') {
                        $colorName = 'rose';
                    } elseif ($stage['status'] === 'cancelled') {
                        $colorName = 'gray';
                    }
                    $bgClass = "bg-{$colorName}-c";
                    $textClass = "text-{$colorName}-c";
                @endphp

                <div class="tl-node-wrap">
                    <div class="tl-node">
                        <div class="tl-node-inner {{ $bgClass }}"></div>
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

                @if (!$loop->last)
                    @php
                        $nextStage = $stages[$index + 1];
                        $lineColorName = 'gray';
                        
                        if ($nextStage['status'] !== 'pending') {
                            $lineColorName = 'primary';
                            if ($nextStage['status'] === 'failed') {
                                $lineColorName = 'rose';
                            } elseif ($nextStage['status'] === 'cancelled') {
                                $lineColorName = 'gray';
                            }
                        }
                    @endphp
                    <div class="tl-line-segment bg-{{ $lineColorName }}-c"></div>
                @endif

                @endforeach
            </div>
        </div>
    </div>
    @endif

    @if ($viewType === 'both' || $viewType === 'stream')
    <div class="rounded-lg border border-gray-200 bg-gray-950 p-3 sm:p-4 overflow-hidden">
        <div
            class="h-96 max-w-full overflow-x-auto overflow-y-auto rounded-md border border-gray-800 bg-gray-950 p-3"
            id="log-container-{{ $testId }}">
            <pre class="m-0 min-w-max whitespace-pre font-mono text-xs sm:text-sm leading-relaxed text-emerald-300"><code class="block">{{ $this->formattedLogs }}</code></pre>
        </div>
    </div>

    <script>
        document.addEventListener('livewire:initialized', () => {
            setInterval(() => {
                const container = document.getElementById('log-container-{{ $testId }}');
                if (container) {
                    // Only auto-scroll if user is already near the bottom
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