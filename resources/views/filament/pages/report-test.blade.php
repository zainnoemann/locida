<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center justify-between gap-3">
                <span>Playwright Report</span>
                @livewire('test-report', ['testId' => $this->record->id, 'renderMode' => 'link'], key('test-report-link-' . $this->record->id))
            </div>
        </x-slot>

        @livewire('test-report', ['testId' => $this->record->id], key('test-report-full-' . $this->record->id))
    </x-filament::section>
</x-filament-panels::page>
