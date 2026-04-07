<?php

namespace App\Filament\Resources\AppResource\Actions;

use App\Filament\Resources\AppResource;
use App\Jobs\GenerateAppJob;
use App\Models\App;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class RetryFailedAppAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'retry_failed';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Retry Failed')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (App $record): bool => Auth::check() && $record->status === App::STATUS_FAILED)
            ->successRedirectUrl(fn (App $record): string => AppResource::getUrl('generate', ['record' => $record]))
            ->action(function (App $record): void {
                GenerateAppJob::dispatch($record);

                Notification::make()
                    ->success()
                    ->title('Retry started')
                    ->body('Failed app has been queued for regeneration.')
                    ->send();
            });
    }
}
