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
                    ->rule(function (callable $get, ?\App\Models\Test $record): \Closure {
                        return function (string $attribute, mixed $value, \Closure $fail) use ($get, $record): void {
                            $repoName = trim((string) $value);
                            $sourceBranch = trim((string) ($get('source_branch') ?? ''));
                            $testBranch = trim((string) ($get('test_branch') ?? ''));

                            if ($repoName === '' || $sourceBranch === '' || $testBranch === '') {
                                return;
                            }

                            $query = \App\Models\Test::query()
                                ->where('repo_name', $repoName)
                                ->where('source_branch', $sourceBranch)
                                ->where('test_branch', $testBranch);

                            if ($record?->exists) {
                                $query->whereKeyNot($record->getKey());
                            }

                            if ($query->exists()) {
                                $fail('A test for this repository, source branch, and test branch already exists.');
                            }
                        };
                    })
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
                    ->placeholder('Enter test branch name')
                    ->required()
                    ->default('playwright')
                    ->rule(function (callable $get, \App\Services\GiteaService $giteaService, ?\App\Models\Test $record): \Closure {
                        return function (string $attribute, mixed $value, \Closure $fail) use ($get, $giteaService, $record): void {
                            $branchName = trim((string) $value);
                            if ($branchName === '') {
                                return;
                            }

                            $repoUrl = trim((string) ($get('repo_url') ?? ''));
                            if ($repoUrl === '') {
                                return;
                            }

                            $isEditingOwnUnchangedBranch = $record?->exists
                                && trim((string) $record->repo_url) === $repoUrl
                                && trim((string) $record->test_branch) === $branchName;

                            if ($isEditingOwnUnchangedBranch) {
                                return;
                            }

                            $existingBranches = $giteaService->getBranchesByCloneUrl($repoUrl, false);
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
                    ->maxLength(2048),
            ]);
    }
}
