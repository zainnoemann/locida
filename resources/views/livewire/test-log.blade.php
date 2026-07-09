{{-- 
    Livewire Component View: Test Log
    Purpose: Renders the real-time test generation log and a vertical timeline
    representing the stages of the generation process with subtasks.
    The component polls every second to update the UI as new logs arrive.
--}}
<div wire:poll.1s>
    @php
    $timeline = $this->timeline;
    $stages = $timeline['stages'];
    @endphp

    <div class="w-full">
        @if ($timeline['isWaiting'])
        <div class="mb-4 p-4 text-sm text-amber-700 bg-amber-100 rounded-lg dark:bg-amber-200 dark:text-amber-800" role="alert">
            <span class="font-medium">Please wait!</span> The generator is starting up...
        </div>
        @endif

        {{-- Vertical Stepper for Pipeline Phases --}}
        <div class="py-6 px-4 sm:px-8">
            <ol class="relative border-l border-gray-200 dark:border-gray-700 ml-4 space-y-10">
                @foreach ($stages as $index => $stage)
                @php
                    $status = $stage['status'];
                    
                    // Colors and Icons based on status
                    $iconBg = 'bg-gray-100 dark:bg-gray-700';
                    $titleColor = 'text-gray-500 dark:text-gray-400';
                    $iconHtml = '<div class="w-2.5 h-2.5 rounded-full bg-gray-400"></div>'; // default pending dot
                    
                    if ($status === 'done') {
                        $iconBg = 'bg-emerald-100 dark:bg-emerald-900/30';
                        $titleColor = 'text-emerald-700 dark:text-emerald-400';
                        $iconHtml = '<svg class="w-3.5 h-3.5 text-emerald-500 dark:text-emerald-400" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 16 12"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1 5.917 5.724 10.5 15 1.5"/></svg>';
                    } elseif ($status === 'active') {
                        $iconBg = 'bg-amber-100 dark:bg-amber-900/30';
                        $titleColor = 'text-amber-600 dark:text-amber-400';
                        $iconHtml = '<svg class="w-4 h-4 text-amber-500 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
                    } elseif ($status === 'failed' || $status === 'cancelled') {
                        $iconBg = 'bg-rose-100 dark:bg-rose-900/30';
                        $titleColor = 'text-rose-600 dark:text-rose-400';
                        $iconHtml = '<svg class="w-3.5 h-3.5 text-rose-500 dark:text-rose-400" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/></svg>';
                    }
                @endphp

                <li class="ml-8">
                    <span class="absolute flex items-center justify-center w-8 h-8 rounded-full -left-4 ring-4 ring-white dark:ring-gray-900 {{ $iconBg }}">
                        {!! $iconHtml !!}
                    </span>
                    <h3 class="flex items-center mb-1 text-lg font-semibold {{ $titleColor }}">
                        {{ $stage['label'] }}
                        @if(!empty($stage['duration']))
                            <span class="bg-gray-100 text-gray-800 text-xs font-medium mr-2 px-2.5 py-0.5 rounded dark:bg-gray-700 dark:text-gray-300 ml-3">
                                {{ $stage['duration'] }}
                            </span>
                        @endif
                    </h3>
                    <p class="mb-3 text-sm font-normal text-gray-600 dark:text-gray-400">{{ $stage['description'] }}</p>
                    
                    {{-- Subtasks List --}}
                    @if(isset($stage['subtasks']) && count($stage['subtasks']) > 0)
                        <ul class="space-y-2 mt-3 text-sm">
                            @foreach($stage['subtasks'] as $subtask)
                                @php
                                    $subStatus = $subtask['status'];
                                    $subIconColor = 'text-gray-300 dark:text-gray-600';
                                    $subTextColor = 'text-gray-500 dark:text-gray-500';
                                    $subIcon = '<svg class="w-4 h-4 mr-2" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><circle cx="12" cy="12" r="10" stroke-width="2"/></svg>';

                                    if ($subStatus === 'done') {
                                        $subIconColor = 'text-emerald-500';
                                        $subTextColor = 'text-gray-800 dark:text-gray-200';
                                        $subIcon = '<svg class="w-4 h-4 mr-2" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
                                    } elseif ($subStatus === 'active') {
                                        $subIconColor = 'text-amber-500';
                                        $subTextColor = 'text-gray-900 dark:text-white font-medium';
                                        $subIcon = '<svg class="w-4 h-4 mr-2 animate-spin" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
                                    } elseif ($subStatus === 'failed' || $subStatus === 'cancelled') {
                                        $subIconColor = 'text-rose-500';
                                        $subTextColor = 'text-rose-600 dark:text-rose-400';
                                        $subIcon = '<svg class="w-4 h-4 mr-2" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
                                    }
                                @endphp
                                <li class="flex items-center {{ $subTextColor }}">
                                    <span class="{{ $subIconColor }}">
                                        {!! $subIcon !!}
                                    </span>
                                    {{ $subtask['label'] }}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>
                @endforeach
            </ol>
        </div>

        {{-- Collapsible Raw Log --}}
        <div x-data="{ open: false }" class="mt-8 pt-4 border-t border-gray-200 dark:border-gray-800">
            <button @click="open = !open" type="button" class="flex items-center justify-between w-full p-4 font-medium text-left text-gray-500 border border-gray-200 rounded-lg hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800 transition-colors">
                <span class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-gray-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M4 15V9a2 2 0 012-2h12a2 2 0 012 2v6a2 2 0 01-2 2H6a2 2 0 01-2-2z" /></svg>
                    Raw Logs
                </span>
                <svg data-accordion-icon class="w-5 h-5 shrink-0 transition-transform duration-200" :class="{'rotate-180': open}" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 10 6">
                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5 5 1 1 5"/>
                </svg>
            </button>
            <div x-show="open" x-transition.opacity.duration.300ms class="mt-3">
                <div class="rounded-lg border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-3 sm:p-4 overflow-hidden">
                    <div
                        class="h-96 max-w-full overflow-x-auto overflow-y-auto rounded-md border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-black p-3"
                        id="log-container-{{ $testId }}">
                        <pre class="m-0 min-w-max whitespace-pre font-mono text-xs sm:text-sm leading-relaxed text-emerald-600 dark:text-emerald-300"><code class="block">{{ $this->formattedLogs }}</code></pre>
                    </div>
                </div>
            </div>
        </div>

    </div>

    {{-- Auto-scroll script for the raw log --}}
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
</div>