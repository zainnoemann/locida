<x-filament-panels::page>
    <div class="flex flex-col gap-8">
        <x-filament::section>
            <x-slot name="heading">
                Generation Timeline
            </x-slot>

            @livewire('test-log', ['testId' => $this->record->id, 'viewType' => 'timeline'])
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center justify-between gap-3">
                    <span>Playwright Report</span>
                    @livewire('test-report', ['testId' => $this->record->id, 'renderMode' => 'link'], key('test-report-link-' . $this->record->id))
                </div>
            </x-slot>

            @livewire('test-report', ['testId' => $this->record->id], key('test-report-full-' . $this->record->id))
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                Generate Stream
            </x-slot>

            @livewire('test-log', ['testId' => $this->record->id, 'viewType' => 'stream'])
        </x-filament::section>
    </div>
</x-filament-panels::page>