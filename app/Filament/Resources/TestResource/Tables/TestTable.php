<?php

namespace App\Filament\Resources\TestResource\Tables;

use App\Filament\Resources\TestResource\Actions\GenerateTestAction;
use App\Filament\Resources\TestResource\Actions\RetryFailedTestAction;
use App\Filament\Resources\TestResource;
use App\Jobs\GenerateTestJob;
use App\Models\Test;
use Filament\Actions\Action;
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

class TestTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn(Builder $query) => $query->select([
                'id',
                'name',
                'repo_name',
                'repo_url',
                'source_branch',
                'test_branch',
                'app_url',
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
                    ->label('Test Name')
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('repo_name')
                    ->label('Repository')
                    ->searchable()
                    ->copyable()
                    ->limit(40)
                    ->tooltip(fn(?string $state): ?string => $state),
                \Filament\Tables\Columns\TextColumn::make('repo_url')
                    ->label('Repository URL')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable()
                    ->limit(50)
                    ->tooltip(fn(?string $state): ?string => $state),
                \Filament\Tables\Columns\TextColumn::make('source_branch')
                    ->label('Source Branch')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('test_branch')
                    ->label('Test Branch')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->searchable(),
                \Filament\Tables\Columns\TextColumn::make('app_url')
                    ->label('App URL')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable()
                    ->limit(50)
                    ->tooltip(fn(?string $state): ?string => $state),
                \Filament\Tables\Columns\TextColumn::make('status')
                    ->label('Result Status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn(?string $state): string => Test::statusOptions()[$state ?? Test::STATUS_NONE] ?? 'Unknown')
                    ->color(fn(string $state): string => match ($state) {
                        Test::STATUS_COMPLETED => 'success',
                        Test::STATUS_GENERATING => 'warning',
                        Test::STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn(string $state): string => match ($state) {
                        Test::STATUS_COMPLETED => 'heroicon-m-check-circle',
                        Test::STATUS_GENERATING => 'heroicon-m-arrow-path',
                        Test::STATUS_FAILED => 'heroicon-m-x-circle',
                        default => 'heroicon-m-minus-circle',
                    }),
                \Filament\Tables\Columns\TextColumn::make('error')
                    ->label('Last Error')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('-')
                    ->limit(80)
                    ->wrap()
                    ->tooltip(fn(?string $state): ?string => $state)
                    ->color('danger'),
                \Filament\Tables\Columns\TextColumn::make('generated_at')
                    ->label('Last Generated')
                    ->placeholder('-')
                    ->since()
                    ->tooltip(fn(Test $record): string => $record->generated_at?->toDateTimeString() ?? 'Not generated yet')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Test::statusOptions())
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
                            ->when($data['generated_from'] ?? null, fn(Builder $query, $date): Builder => $query->whereDate('generated_at', '>=', $date))
                            ->when($data['generated_until'] ?? null, fn(Builder $query, $date): Builder => $query->whereDate('generated_at', '<=', $date));
                    }),
                Filter::make('failed_only')
                    ->label('Failed Only')
                    ->toggle()
                    ->query(fn(Builder $query): Builder => $query->where('status', Test::STATUS_FAILED)),
            ])
            ->defaultSort('generated_at', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No tests configured')
            ->emptyStateDescription('Create a test source first, then run generation from the table action.')
            ->emptyStateIcon('heroicon-o-beaker')
            ->recordActions([
                GenerateTestAction::make(),
                RetryFailedTestAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    \Filament\Actions\BulkAction::make('retry_failed_bulk')
                        ->label('Retry Failed')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->visible(fn(): bool => Auth::check())
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $queued = 0;

                            $records
                                ->where('status', Test::STATUS_FAILED)
                                ->each(function (Test $record) use (&$queued): void {
                                    GenerateTestJob::dispatch($record);
                                    $queued++;
                                });

                            Notification::make()
                                ->title('Bulk retry finished')
                                ->body($queued > 0
                                    ? "Queued {$queued} failed test(s) for regeneration."
                                    : 'No failed tests found in selected rows.')
                                ->success()
                                ->send();
                        }),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
