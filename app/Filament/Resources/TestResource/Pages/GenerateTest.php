<?php

namespace App\Filament\Resources\TestResource\Pages;

use App\Filament\Resources\TestResource;
use App\Models\Test;
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
                ->label('Regenerate')
                ->icon('heroicon-m-arrow-path')
                ->color('warning')
                ->visible(fn(): bool => Auth::check())
                ->disabled(fn(): bool => $this->record->status === Test::STATUS_GENERATING)
                ->requiresConfirmation()
                ->action(function (): void {
                    \App\Jobs\GenerateTestJob::dispatch($this->record);
                    Notification::make()
                        ->success()
                        ->title('Regeneration started')
                        ->body('Log stream is now live.')
                        ->send();
                }),
        ];
    }
}
