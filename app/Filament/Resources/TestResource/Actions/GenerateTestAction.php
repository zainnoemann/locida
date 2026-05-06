<?php

namespace App\Filament\Resources\TestResource\Actions;

use App\Filament\Resources\TestResource;
use App\Jobs\GenerateTestJob;
use App\Models\Test;
use App\Services\PlaywrightGeneratorService;
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

        $this->label(fn(Test $record): string => $record->status === Test::STATUS_GENERATING
                ? 'Cancel'
                : 'Generate')
            ->icon(fn(Test $record): string => $record->status === Test::STATUS_GENERATING
                ? 'heroicon-m-x-circle'
                : 'heroicon-o-play')
            ->color(fn(Test $record): string => $record->status === Test::STATUS_GENERATING
                ? 'danger'
                : 'success')
            ->visible(fn(Test $record): bool => Auth::check())
            ->requiresConfirmation(fn(Test $record): bool => in_array($record->status, [Test::STATUS_FAILED, Test::STATUS_GENERATING], true))
            ->modalHeading(fn(Test $record): ?string => match ($record->status) {
                Test::STATUS_FAILED => 'Failed test action',
                Test::STATUS_GENERATING => 'Generation in progress',
                default => null,
            })
            ->modalSubmitAction(fn(Action $action): Action => $action
                ->label('View')
                ->color('success'))
            ->modalCancelAction(fn(Action $action): Action => $action->hidden())
            ->extraModalFooterActions(fn(Action $action): array => match ($action->getRecord()?->status) {
                Test::STATUS_FAILED => [
                    $action->makeModalSubmitAction('retry', arguments: ['failed_action' => 'retry'])
                        ->label('Retry')
                        ->color('warning'),
                ],
                Test::STATUS_GENERATING => [
                    $action->makeModalSubmitAction('cancel_execution', arguments: ['generation_action' => 'cancel'])
                        ->label('Cancel')
                        ->color('danger'),
                ],
                default => [],
            })
            ->successRedirectUrl(fn(Test $record): string => TestResource::getUrl('generate', ['record' => $record]))
            ->action(function (Test $record, array $arguments): void {
                if ($record->status === Test::STATUS_GENERATING) {
                    $selectedAction = (string) ($arguments['generation_action'] ?? 'view');

                    if ($selectedAction === 'cancel') {
                        if (app(PlaywrightGeneratorService::class)->cancelGeneration($record)) {
                            $record->refresh();

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

                    // View log
                    Notification::make()
                        ->warning()
                        ->title('Opening generation log')
                        ->body('Displaying the live process view.')
                        ->send();

                    return;
                }

                if (in_array($record->status, [Test::STATUS_COMPLETED, 'complete'], true)) {
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
                        $record->refresh();

                        Notification::make()
                            ->success()
                            ->title('Retry started')
                            ->body('Failed test has been queued for regeneration.')
                            ->send();

                        return;
                    }

                    // View log
                    Notification::make()
                        ->warning()
                        ->title('Opening generation log')
                        ->body('Displaying the latest generation output.')
                        ->send();

                    return;
                }

                GenerateTestJob::dispatch($record);
                $record->refresh();

                Notification::make()
                    ->success()
                    ->title('Generation started')
                    ->body('Log stream is now live.')
                    ->send();
            });
    }
}
