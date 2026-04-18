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
                        $set('source_branch', $repo['default_branch'] ?? 'main');
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
                \Filament\Forms\Components\Select::make('source_branch')
                    ->label('Source Branch')
                    ->options(function (callable $get, \App\Services\GiteaService $giteaService): array {
                        $repoUrl = (string) ($get('repo_url') ?? '');
                        if ($repoUrl === '') {
                            return [];
                        }

                        $branches = collect($giteaService->getBranchesByCloneUrl($repoUrl))
                            ->filter(fn($branch) => is_string($branch) && $branch !== '')
                            ->values();

                        $current = (string) ($get('source_branch') ?? '');
                        if ($current !== '' && ! $branches->contains($current)) {
                            $branches->prepend($current);
                        }

                        return $branches
                            ->mapWithKeys(fn(string $branch): array => [$branch => $branch])
                            ->all();
                    })
                    ->placeholder('Select a branch')
                    ->required()
                    ->default('main')
                    ->helperText('Select the source repository branch used for test generation.')
                    ->searchable()
                    ->preload()
                    ->disabled(fn(callable $get): bool => empty($get('repo_url')))
                    ->live(),
                \Filament\Forms\Components\TextInput::make('test_branch')
                    ->label('Test Branch')
                    ->placeholder('playwright')
                    ->required()
                    ->default('playwright')
                    ->helperText('Set the target branch where generated tests and reports will be pushed.')
                    ->rule(function (callable $get, \App\Services\GiteaService $giteaService): \Closure {
                        return function (string $attribute, mixed $value, \Closure $fail) use ($get, $giteaService): void {
                            $branchName = trim((string) $value);
                            if ($branchName === '') {
                                return;
                            }

                            $repoUrl = trim((string) ($get('repo_url') ?? ''));
                            if ($repoUrl === '') {
                                return;
                            }

                            $existingBranches = $giteaService->getBranchesByCloneUrl($repoUrl);
                            if (in_array($branchName, $existingBranches, true)) {
                                $fail('Test branch already exists in the repository. Please use a different branch name.');
                            }
                        };
                    })
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
