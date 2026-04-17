<?php

namespace App\Filament\Resources\TestResource\Schemas;

use Filament\Schemas\Schema;

class TestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Forms\Components\Select::make('repo_url')
                    ->label('Source Repository')
                    ->options(function (\App\Services\GiteaService $giteaService) {
                        return collect($giteaService->getRepositories())
                            ->filter(fn($repo) => is_array($repo) && isset($repo['clone_url'], $repo['full_name']))
                            ->mapWithKeys(fn($repo) => [$repo['clone_url'] => $repo['full_name']])
                            ->all();
                    })
                    ->helperText('Select a source repository from Gitea to generate Playwright tests.')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set, \App\Services\GiteaService $giteaService) {
                        $repo = collect($giteaService->getRepositories())
                            ->first(fn($item) => is_array($item) && ($item['clone_url'] ?? null) === $state);

                        if (! is_array($repo)) {
                            return;
                        }

                        $set('name', $repo['name'] ?? '');
                        $set('repo_name', $repo['full_name'] ?? '');
                    }),
                \Filament\Forms\Components\TextInput::make('name')
                    ->label('Test Name')
                    ->placeholder('Enter test name')
                    ->required()
                    ->maxLength(255),
                \Filament\Forms\Components\TextInput::make('repo_name')
                    ->label('Repository Name')
                    ->placeholder('Enter repository name')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                \Filament\Forms\Components\TextInput::make('app_url')
                    ->label('App URL')
                    ->placeholder('Enter App URL')
                    ->url()
                    ->required()
                    ->default(config('app.url'))
                    ->maxLength(2048),
            ]);
    }
}
