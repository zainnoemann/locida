<?php

namespace App\Filament\Resources\AppResource\Actions;

use App\Filament\Resources\AppResource;
use App\Jobs\GenerateAppJob;
use App\Models\App;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class GenerateAppAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'generate';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Generate')
            ->icon('heroicon-o-play')
            ->color('success')
            ->disabled(fn (App $record): bool => $record->status === App::STATUS_GENERATING)
            ->visible(fn (): bool => Auth::check())
            ->successRedirectUrl(fn (App $record): string => AppResource::getUrl('generate', ['record' => $record]))
            ->action(function (App $record): void {
                if ($record->status === App::STATUS_GENERATING) {
                    Notification::make()
                        ->warning()
                        ->title('Generation already in progress')
                        ->body('Please wait until the current generation finishes.')
                        ->send();

                    return;
                }

                if ($record->status === App::STATUS_COMPLETED) {
                    Notification::make()
                        ->success()
                        ->title('Opening generation log')
                        ->body('Displaying the latest generation output.')
                        ->send();

                    return;
                }

                GenerateAppJob::dispatch($record);

                Notification::make()
                    ->success()
                    ->title('Generation started')
                    ->body('Log stream is now live.')
                    ->send();
            });
    }
}
