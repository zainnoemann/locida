<?php

namespace App\Filament\Resources\AppResource\Pages;

use App\Filament\Resources\AppResource;
use App\Jobs\GenerateAppJob;
use App\Models\App;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Auth;

class GenerateApp extends Page
{
    protected static string $resource = AppResource::class;

    protected string $view = 'filament.pages.generate-app';

    public App $record;

    public function mount(App $record): void
    {
        $this->record = $record;
    }

    public function getTitle(): string
    {
        return "{$this->record->name} App";
    }

    public function getHeading(): string
    {
        return "{$this->record->name} App";
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
                ->label('Regenerate')
                ->icon('heroicon-m-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => Auth::check())
                ->disabled(fn (): bool => $this->record->status === App::STATUS_GENERATING)
                ->requiresConfirmation()
                ->action(function (): void {
                    GenerateAppJob::dispatch($this->record);

                    Notification::make()
                        ->success()
                        ->title('Regeneration started')
                        ->body('Log stream is now live.')
                        ->send();
                }),
        ];
    }
}
