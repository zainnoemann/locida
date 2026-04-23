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
            ->visible(fn(Test $record): bool => Auth::check())
            ->requiresConfirmation(fn(Test $record): bool => $record->status === Test::STATUS_FAILED)
            ->modalHeading(fn(Test $record): string => $record->status === Test::STATUS_FAILED
                ? 'Failed test action'
                : 'Generate test')
            ->modalSubmitActionLabel(fn(Test $record): string => $record->status === Test::STATUS_FAILED
                ? 'View'
                : 'Generate')
            ->modalCancelActionLabel('Cancel')
            ->extraModalFooterActions(fn(Action $action): array => $action->getRecord()?->status === Test::STATUS_FAILED
                ? [
                    $action->makeModalSubmitAction('retry', arguments: ['failed_action' => 'retry'])
                        ->label('Retry')
                        ->color('warning'),
                ]
                : [])
            ->successRedirectUrl(fn(Test $record): string => TestResource::getUrl('generate', ['record' => $record]))
            ->action(function (Test $record, array $arguments): void {
                if ($record->status === Test::STATUS_GENERATING) {
                    Notification::make()
                        ->info()
                        ->title('Generation in progress')
                        ->body('Opening the live process view.')
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

                if ($record->status === Test::STATUS_FAILED) {
                    $selectedAction = (string) ($arguments['failed_action'] ?? 'view');

                    if ($selectedAction === 'retry') {
                        GenerateTestJob::dispatch($record);

                        Notification::make()
                            ->success()
                            ->title('Retry started')
                            ->body('Failed test has been queued for regeneration.')
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->warning()
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
