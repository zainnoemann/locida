<?php

namespace App\Filament\Resources\TestResource\Pages;

use App\Filament\Resources\TestResource;
use App\Jobs\GenerateTestJob;
use App\Models\Test;
use App\Services\TestService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Custom Filament page displaying the live generation status of a Playwright test.
 * Embeds Livewire log views and handles header actions to dispatch or abort generation jobs.
 */
class GenerateTest extends Page
{
    public Test $record;
    protected static string $resource = TestResource::class;

    protected string $view = 'filament.pages.generate-test';

    public function mount(Test $record): void
    {
        $this->record = $record;
    }

    /**
     * Refreshes the model state before each Livewire request to ensure
     * header buttons reflect the latest DB status (e.g., generating vs completed).
     */
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

    /**
     * Configures the dynamic "Regenerate" / "Cancel" action button.
     * The behavior and appearance toggle based on whether the test is currently generating.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('report')
                ->label('Report')
                ->icon('heroicon-m-document-chart-bar')
                ->color('gray')
                ->url(fn (): string => static::getResource()::getUrl('report', ['record' => $this->record]))
                ->visible(fn (): bool => ! $this->record->isGenerating()),
            Action::make('regenerate')
                ->label(fn (): string => $this->record->isGenerating()
                    ? 'Cancel'
                    : 'Regenerate')
                ->icon(fn (): string => $this->record->isGenerating()
                    ? 'heroicon-m-x-circle'
                    : 'heroicon-m-arrow-path')
                ->color(fn (): string => $this->record->isGenerating()
                    ? 'danger'
                    : 'warning')
                ->visible(fn (): bool => Auth::check())
                ->requiresConfirmation()
                ->modalHeading(fn (): string => $this->record->isGenerating()
                    ? 'Cancel generation?'
                    : 'Regenerate test?')
                ->modalSubmitActionLabel(fn (): string => $this->record->isGenerating()
                    ? 'Cancel'
                    : 'Regenerate')
                ->modalCancelActionLabel(fn (): string => $this->record->isGenerating()
                    ? 'Keep running'
                    : 'Close')
                ->action(function (): void {
                    // Logic to abort an actively running job
                    if ($this->record->isGenerating()) {
                        if (app(TestService::class)->cancelGeneration($this->record)) {
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

                    // Logic to dispatch a fresh generation request
                    GenerateTestJob::dispatch($this->record->id);
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
