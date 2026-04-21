<?php

namespace App\Filament\Resources\TestResource\Actions;

use App\Filament\Resources\TestResource;
use App\Jobs\GenerateTestJob;
use App\Models\Test;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class RetryFailedTestAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'retry_failed';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Retry')
            ->icon('heroicon-o-arrow-path')
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn(Test $record): bool => Auth::check() && $record->status === Test::STATUS_FAILED)
            ->successRedirectUrl(fn(Test $record): string => TestResource::getUrl('generate', ['record' => $record]))
            ->action(function (Test $record): void {
                GenerateTestJob::dispatch($record);

                Notification::make()
                    ->success()
                    ->title('Retry started')
                    ->body('Failed test has been queued for regeneration.')
                    ->send();
            });
    }
}
