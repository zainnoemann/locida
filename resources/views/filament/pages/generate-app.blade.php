<x-filament-panels::page>
    <div class="flex flex-col gap-8">
        <x-filament::section>
            <x-slot name="heading">
                Generation Timeline
            </x-slot>

            @livewire('app-log', ['appId' => $this->record->id, 'viewType' => 'timeline'])
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                Generate Stream
            </x-slot>

            @livewire('app-log', ['appId' => $this->record->id, 'viewType' => 'stream'])
        </x-filament::section>
    </div>
</x-filament-panels::page>
