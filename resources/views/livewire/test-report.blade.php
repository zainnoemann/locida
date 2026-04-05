<div wire:poll.1s>
    @php
    $report = $this->report;
    $stats = $report['stats'] ?? ['expected' => 0, 'unexpected' => 0, 'flaky' => 0, 'skipped' => 0, 'total' => 0];
    $specs = $report['specs'] ?? [];
    $filteredSpecs = $this->filterSpecs($specs);
    $filter = $this->statusFilter;

    $filterButtons = [
    'all' => ['label' => 'All', 'count' => $stats['total']],
    'passed' => ['label' => 'Passed', 'count' => $stats['expected']],
    'failed' => ['label' => 'Failed', 'count' => $stats['unexpected']],
    'flaky' => ['label' => 'Flaky', 'count' => $stats['flaky']],
    'skipped' => ['label' => 'Skipped', 'count' => $stats['skipped']],
    ];
    @endphp

    @if (! $report['available'])
    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-300">
        {{ $report['message'] ?? 'report.json is not available yet.' }}
    </div>
    @else
    <div class="mb-4 flex flex-nowrap items-center gap-3 overflow-x-auto pb-1">
        {{-- Search --}}
        <div class="min-w-[14rem] flex-1">
            <input
                type="text"
                wire:model.live.debounce.300ms="keyword"
                placeholder="Search test"
                class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 placeholder:text-gray-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-100" />
        </div>

        {{-- Filter --}}
        <div class="shrink-0 inline-flex overflow-hidden rounded-lg border border-gray-300 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900/40">
            @foreach ($filterButtons as $key => $btn)
            @php
            $isActive = $filter === $key;
            $borderClass = $loop->last ? '' : 'border-r border-gray-300 dark:border-gray-700';
            $colorClass = $isActive
            ? 'bg-primary-600 text-white hover:bg-primary-700 dark:bg-primary-600'
            : 'text-gray-700 hover:bg-gray-50 hover:text-primary-700 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white';
            @endphp
            <button
                type="button"
                class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium transition select-none focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-inset {{ $borderClass }} {{ $colorClass }}"
                wire:click="setStatusFilter('{{ $key }}')">
                {{ $btn['label'] }}
                <span class="rounded-full px-2 py-0.5 text-xs font-bold tabular-nums {{ $isActive ? 'bg-white/25 text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">
                    {{ $btn['count'] }}
                </span>
            </button>
            @endforeach
        </div>
    </div>

    {{-- Spec list --}}
    <div class="rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900/40">
        @if (empty($filteredSpecs))
        <div class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
            @if (trim((string) $this->keyword) !== '' || $this->statusFilter !== 'all')
            No spec matches current filter/search.
            @else
            No spec entries found in report.json.
            @endif
        </div>
        @else
        @php
        $groupedSpecs = collect($filteredSpecs)->groupBy('group');
        @endphp
        <div class="max-h-[32rem] overflow-y-auto">
            @foreach ($groupedSpecs as $groupName => $groupSpecs)
            <div x-data="{ expanded: true }" class="border-b border-gray-200 last:border-0 dark:border-gray-700">
                <button
                    type="button"
                    @click="expanded = !expanded"
                    class="flex w-full items-center bg-gray-100 px-4 py-3 text-left text-sm font-semibold text-gray-700 transition hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700/80">
                    <span>{{ $groupName }}</span>
                </button>
                <ul x-show="expanded" class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($groupSpecs as $spec)
                    @php
                    $status = strtolower((string) ($spec['status'] ?? 'unknown'));
                    $chipClass = 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300';
                    if (in_array($status, ['passed', 'expected'], true)) {
                    $chipClass = 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300';
                    } elseif ($status === 'flaky') {
                    $chipClass = 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300';
                    } elseif (in_array($status, ['failed', 'timedout', 'interrupted'], true)) {
                    $chipClass = 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300';
                    } elseif ($status === 'skipped') {
                    $chipClass = 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
                    }
                    $specDurationMs = (int) ($spec['durationMs'] ?? 0);
                    @endphp
                    <li class="px-4 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $spec['title'] }}</p>
                            <div class="flex items-center gap-2">
                                <span class="rounded-full px-2 py-0.5 text-xs uppercase tracking-wide {{ $chipClass }}">{{ $spec['status'] }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ number_format($specDurationMs / 1000, 2) }}s</span>
                            </div>
                        </div>
                        @if (! empty($spec['message']))
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $spec['message'] }}</p>
                        @endif
                    </li>
                    @endforeach
                </ul>
            </div>
            @endforeach
        </div>
        @endif
    </div>
    @endif
</div>