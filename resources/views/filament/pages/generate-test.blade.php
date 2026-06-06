{{-- 
    Filament Page Template: Generate Test
    Purpose: Provides the UI layout for the test generation process.
    It embeds Livewire components to display a visual timeline, the full log stream, 
    and the final Playwright HTML report link once generation completes.
--}}
<x-filament-panels::page wire:poll.2s>
    <div class="flex flex-col gap-8">
        {{-- Visual timeline showing the current stage of the generation process --}}
        <x-filament::section>
            <x-slot name="heading">
                Generation Timeline
            </x-slot>

            @livewire('test-log', ['testId' => $this->record->id, 'viewType' => 'timeline'])
        </x-filament::section>

        {{-- Section to display the parsed JSON report and a link to the HTML report --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center justify-between gap-3">
                    <span>Playwright Report</span>
                    {{-- Render the 'Report' button link separately --}}
                    @livewire('test-report', ['testId' => $this->record->id, 'renderMode' => 'link'], key('test-report-link-' . $this->record->id))
                </div>
            </x-slot>

            {{-- Render the full parsed specs list --}}
            @livewire('test-report', ['testId' => $this->record->id], key('test-report-full-' . $this->record->id))
        </x-filament::section>

        {{-- Live terminal-like log stream --}}
        <x-filament::section>
            <x-slot name="heading">
                Generate Stream
            </x-slot>

            @livewire('test-log', ['testId' => $this->record->id, 'viewType' => 'stream'])
        </x-filament::section>
    </div>
</x-filament-panels::page>