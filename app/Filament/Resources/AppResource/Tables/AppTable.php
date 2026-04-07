<?php

namespace App\Filament\Resources\AppResource\Tables;

use App\Filament\Resources\AppResource\Actions\GenerateAppAction;
use App\Filament\Resources\AppResource\Actions\RetryFailedAppAction;
use App\Jobs\GenerateAppJob;
use App\Models\App;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class AppTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->select([
                'id',
                'name',
                'repo_name',
                'repo_url',
                'status',
                'error',
                'started_at',
                'failed_at',
                'generated_at',
                'created_at',
                'updated_at',
            ]))
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('name')
                    ->label('App Name')
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('repo_name')
                    ->label('Repository')
                    ->searchable()
                    ->copyable()
                    ->limit(40)
                    ->tooltip(fn (?string $state): ?string => $state),
                \Filament\Tables\Columns\TextColumn::make('repo_url')
                    ->label('Repository URL')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable()
                    ->limit(50)
                    ->tooltip(fn (?string $state): ?string => $state),
                \Filament\Tables\Columns\TextColumn::make('status')
                    ->label('Setup Status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): string => App::statusOptions()[$state ?? App::STATUS_NONE] ?? 'Unknown')
                    ->color(fn (string $state): string => match ($state) {
                        App::STATUS_COMPLETED => 'success',
                        App::STATUS_GENERATING => 'warning',
                        App::STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        App::STATUS_COMPLETED => 'heroicon-m-check-circle',
                        App::STATUS_GENERATING => 'heroicon-m-arrow-path',
                        App::STATUS_FAILED => 'heroicon-m-x-circle',
                        default => 'heroicon-m-minus-circle',
                    }),
                \Filament\Tables\Columns\TextColumn::make('error')
                    ->label('Last Error')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('-')
                    ->limit(80)
                    ->wrap()
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->color('danger'),
                \Filament\Tables\Columns\TextColumn::make('generated_at')
                    ->label('Last Generated')
                    ->placeholder('-')
                    ->since()
                    ->tooltip(fn (App $record): string => $record->generated_at?->toDateTimeString() ?? 'Not generated yet')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(App::statusOptions())
                    ->native(false),
                Filter::make('generated_between')
                    ->label('Generated Between')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('generated_from')
                            ->label('From'),
                        \Filament\Forms\Components\DatePicker::make('generated_until')
                            ->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['generated_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('generated_at', '>=', $date))
                            ->when($data['generated_until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('generated_at', '<=', $date));
                    }),
                Filter::make('failed_only')
                    ->label('Failed Only')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('status', App::STATUS_FAILED)),
            ])
            ->defaultSort('generated_at', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No apps configured')
            ->emptyStateDescription('Create an app source first, then run generation from the table action.')
            ->emptyStateIcon('heroicon-o-cube')
            ->recordActions([
                GenerateAppAction::make(),
                RetryFailedAppAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('retry_failed_bulk')
                        ->label('Retry Failed')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->visible(fn (): bool => Auth::check())
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $queued = 0;

                            $records
                                ->where('status', App::STATUS_FAILED)
                                ->each(function (App $record) use (&$queued): void {
                                    GenerateAppJob::dispatch($record);
                                    $queued++;
                                });

                            Notification::make()
                                ->title('Bulk retry finished')
                                ->body($queued > 0
                                    ? "Queued {$queued} failed app(s) for regeneration."
                                    : 'No failed apps found in selected rows.')
                                ->success()
                                ->send();
                        }),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
