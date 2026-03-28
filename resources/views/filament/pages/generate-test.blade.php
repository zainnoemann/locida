<x-filament-panels::page>
    <div class="space-y-8 w-full" style="margin-bottom: 2rem;">
        <x-filament::section style="margin-bottom: 2rem;">
            <x-slot name="heading">
                Generation Timeline
            </x-slot>

            @livewire('test-log', ['testId' => $this->record->id, 'viewType' => 'timeline'])
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                Generate Stream
            </x-slot>

            @livewire('test-log', ['testId' => $this->record->id, 'viewType' => 'stream'])
        </x-filament::section>
    </div>
</x-filament-panels::page>