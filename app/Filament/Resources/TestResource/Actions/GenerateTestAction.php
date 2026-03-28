<?php

namespace App\Filament\Resources\TestResource\Actions;

use App\Filament\Resources\TestResource;
use App\Jobs\GenerateTestJob;
use App\Models\Test;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class GenerateTestAction extends Action
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
            ->disabled(fn(Test $record): bool => $record->status === Test::STATUS_GENERATING)
            ->visible(fn(): bool => Auth::check())
            ->successRedirectUrl(fn(Test $record): string => TestResource::getUrl('generate', ['record' => $record]))
            ->action(function (Test $record): void {
                if ($record->status === Test::STATUS_GENERATING) {
                    Notification::make()
                        ->warning()
                        ->title('Generation already in progress')
                        ->body('Please wait until the current generation finishes.')
                        ->send();

                    return;
                }

                if ($record->status === Test::STATUS_COMPLETED) {
                    Notification::make()
                        ->success()
                        ->title('Opening generation log')
                        ->body('Displaying the latest generation output.')
                        ->send();

                    return;
                }

                GenerateTestJob::dispatch($record);

                Notification::make()
                    ->success()
                    ->title('Generation started')
                    ->body('Log stream is now live.')
                    ->send();
            });
    }
}
