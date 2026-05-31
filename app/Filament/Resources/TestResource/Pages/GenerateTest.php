<?php

namespace App\Filament\Resources\TestResource\Pages;

use App\Filament\Resources\TestResource;
use App\Models\Test;
use App\Services\PlaywrightGeneratorService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Auth;

class GenerateTest extends Page
{
    protected static string $resource = TestResource::class;

    protected string $view = 'filament.pages.generate-test';

    public Test $record;

    public function mount(Test $record): void
    {
        $this->record = $record;
    }

    public function hydrate(): void
    {
        $this->record->refresh();
    }

    public function getTitle(): string
    {
        return "{$this->record->name} Test";
    }

    public function getHeading(): string
    {
        return "{$this->record->name} Test";
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function getBreadcrumbs(): array
    {
        return [
            static::getResource()::getUrl() => static::getResource()::getPluralModelLabel(),
            static::getResource()::getUrl('edit', ['record' => $this->record]) => $this->record->name,
            static::getResource()::getUrl('generate', ['record' => $this->record]) => 'Generate',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('regenerate')
                ->label(fn(): string => $this->record->isGenerating()
                    ? 'Cancel'
                    : 'Regenerate')
                ->icon(fn(): string => $this->record->isGenerating()
                    ? 'heroicon-m-x-circle'
                    : 'heroicon-m-arrow-path')
                ->color(fn(): string => $this->record->isGenerating()
                    ? 'danger'
                    : 'warning')
                ->visible(fn(): bool => Auth::check())
                ->requiresConfirmation()
                ->modalHeading(fn(): string => $this->record->isGenerating()
                    ? 'Cancel generation?'
                    : 'Regenerate test?')
                ->modalSubmitActionLabel(fn(): string => $this->record->isGenerating()
                    ? 'Cancel'
                    : 'Regenerate')
                ->modalCancelActionLabel(fn(): string => $this->record->isGenerating()
                    ? 'Keep running'
                    : 'Close')
                ->action(function (): void {
                    if ($this->record->isGenerating()) {
                        if (app(PlaywrightGeneratorService::class)->cancelGeneration($this->record)) {
                            $this->record->refresh();

                            Notification::make()
                                ->warning()
                                ->title('Generation cancelled')
                                ->body('The current test generation has been stopped.')
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->info()
                            ->title('Generation already stopped')
                            ->body('The test is no longer generating.')
                            ->send();

                        return;
                    }

                    \App\Jobs\GenerateTestJob::dispatch($this->record->id);
                    $this->record->refresh();

                    Notification::make()
                        ->success()
                        ->title('Regeneration started')
                        ->body('Log stream is now live.')
                        ->send();
                }),
        ];
    }
}
