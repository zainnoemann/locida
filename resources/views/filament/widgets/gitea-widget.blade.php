<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between gap-6">
            <div class="min-w-0 space-y-1">
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
                    Gitea
                </h2>

                @if ($giteaVersion !== '')
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $giteaVersion }}
                    </p>
                @endif
            </div>

            <div class="shrink-0">
                <x-filament::button
                    tag="a"
                    class="w-auto whitespace-nowrap"
                    :href="$giteaUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    icon="heroicon-m-arrow-top-right-on-square"
                >
                    Open Gitea
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
