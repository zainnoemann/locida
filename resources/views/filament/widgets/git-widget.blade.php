{{-- 
    Filament Widget: Git Status
    Purpose: Displays the current connection status and version of the configured Git instance on the Filament Dashboard.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between gap-6">
            <div class="min-w-0 space-y-1">
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
                    {{ $gitName }}
                </h2>

                {{-- Display the fetched Git version if available --}}
                @if ($gitVersion !== '')
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $gitVersion }}
                    </p>
                @endif
            </div>

            <div class="shrink-0">
                {{-- External link to the root Git URL --}}
                <x-filament::button
                    tag="a"
                    class="w-auto whitespace-nowrap"
                    :href="$gitUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    icon="heroicon-m-arrow-top-right-on-square"
                >
                    Open {{ $gitName }}
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
