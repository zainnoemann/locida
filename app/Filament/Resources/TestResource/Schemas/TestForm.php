<?php

namespace App\Filament\Resources\TestResource\Schemas;

use App\Models\Test;
use App\Contracts\GitInterface;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Http;
use Throwable;

class TestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('repo_url')
                    ->label('Source Repository')
                    ->options(function (callable $get, GitInterface $git) {
                        $repos = collect($git->getRepositories())
                            ->filter(fn ($repo) => is_array($repo) && isset($repo['clone_url'], $repo['full_name']))
                            ->mapWithKeys(fn ($repo) => [$repo['clone_url'] => $repo['full_name']]);

                        $current = trim((string) ($get('repo_url') ?? ''));
                        $currentName = trim((string) ($get('repo_name') ?? ''));

                        if ($current !== '' && ! array_key_exists($current, $repos->all())) {
                            $repos->put($current, $currentName !== '' ? $currentName : $current);
                        }

                        return $repos->all();
                    })
                    ->helperText('Select a source repository from your Git to generate Playwright tests.')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set, GitInterface $git) {
                        $repo = collect($git->getRepositories())
                            ->first(fn ($item) => is_array($item) && ($item['clone_url'] ?? null) === $state);

                        if (! is_array($repo)) {
                            return;
                        }

                        $set('name', $repo['name'] ?? '');
                        $set('repo_name', $repo['full_name'] ?? '');
                        $set('source_branch', $repo['default_branch'] ?? 'main');
                    }),
                TextInput::make('name')
                    ->label('Test Name')
                    ->placeholder('Enter test name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('repo_name')
                    ->label('Repository Name')
                    ->placeholder('Enter repository name')
                    ->required()
                    ->rule(function (callable $get, ?Test $record): Closure {
                        return function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                            $repoName = trim((string) $value);
                            $sourceBranch = trim((string) ($get('source_branch') ?? ''));
                            $testBranch = trim((string) ($get('test_branch') ?? ''));

                            if ($repoName === '' || $sourceBranch === '' || $testBranch === '') {
                                return;
                            }

                            $query = Test::query()
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
                Select::make('source_branch')
                    ->label('Source Branch')
                    ->options(function (callable $get, GitInterface $git): array {
                        $repoUrl = (string) ($get('repo_url') ?? '');
                        if ($repoUrl === '') {
                            return [];
                        }

                        $branches = collect($git->getBranchesByCloneUrl($repoUrl))
                            ->filter(fn ($branch) => is_string($branch) && $branch !== '')
                            ->values();

                        $current = (string) ($get('source_branch') ?? '');
                        if ($current !== '' && ! $branches->contains($current)) {
                            $branches->prepend($current);
                        }

                        return $branches
                            ->mapWithKeys(fn (string $branch): array => [$branch => $branch])
                            ->all();
                    })
                    ->placeholder('Select a branch')
                    ->required()
                    ->helperText('Select the source repository branch used for test generation.')
                    ->searchable()
                    ->preload()
                    ->disabled(fn (callable $get): bool => empty($get('repo_url')))
                    ->live(),
                TextInput::make('test_branch')
                    ->label('Test Branch')
                    ->placeholder('Enter test branch name')
                    ->required()
                    ->rule(function (callable $get, GitInterface $git, ?Test $record): Closure {
                        return function (string $attribute, mixed $value, Closure $fail) use ($get, $git, $record): void {
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

                            $existingBranches = $git->getBranchesByCloneUrl($repoUrl, false);
                            if (in_array($branchName, $existingBranches, true)) {
                                $fail('Test branch already exists in the repository. Please use a different branch name.');
                            }
                        };
                    })
                    ->maxLength(255),

                TextInput::make('test_email')
                    ->label('Test Account Email')
                    ->placeholder('Enter test account email')
                    ->email()
                    ->required()
                    ->maxLength(255),
                TextInput::make('test_password')
                    ->label('Test Account Password')
                    ->placeholder('Enter test account password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->maxLength(255),
            ]);
    }
}
