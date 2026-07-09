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
                Timeline
            </x-slot>

            @livewire('test-log', ['testId' => $this->record->id])
        </x-filament::section>
    </div>
</x-filament-panels::page>