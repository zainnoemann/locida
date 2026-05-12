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
                    ->options(function (callable $get, \App\Services\GiteaService $giteaService) {
                        $repos = collect($giteaService->getRepositories())
                            ->filter(fn($repo) => is_array($repo) && isset($repo['clone_url'], $repo['full_name']))
                            ->mapWithKeys(fn($repo) => [$repo['clone_url'] => $repo['full_name']]);

                        $current = trim((string) ($get('repo_url') ?? ''));
                        $currentName = trim((string) ($get('repo_name') ?? ''));

                        if ($current !== '' && ! array_key_exists($current, $repos->all())) {
                            $repos->put($current, $currentName !== '' ? $currentName : $current);
                        }

                        return $repos->all();
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
                    ->rule(function (): \Closure {
                        return function (string $attribute, mixed $value, \Closure $fail): void {
                            $candidate = trim((string) $value);
                            if ($candidate === '') {
                                return;
                            }

                            if (! str_starts_with($candidate, 'http://') && ! str_starts_with($candidate, 'https://')) {
                                $candidate = 'http://' . ltrim($candidate, '/');
                            }

                            $parsed = parse_url($candidate);
                            if ($parsed !== false && ! empty($parsed['host'])) {
                                $host = (string) $parsed['host'];
                                if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
                                    $host = 'host.docker.internal';
                                }

                                $scheme = $parsed['scheme'] ?? 'http';
                                $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
                                $path = $parsed['path'] ?? '';
                                $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
                                $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

                                $candidate = rtrim($scheme . '://' . $host . $port . $path . $query . $fragment, '/');
                            }

                            if (! filter_var($candidate, FILTER_VALIDATE_URL)) {
                                $fail('App URL is invalid.');

                                return;
                            }

                            try {
                                $headResponse = \Illuminate\Support\Facades\Http::timeout(10)
                                    ->retry(1, 200)
                                    ->withOptions(['allow_redirects' => true])
                                    ->head($candidate);

                                if ($headResponse->successful()) {
                                    return;
                                }

                                if (in_array($headResponse->status(), [405, 501], true)) {
                                    $getResponse = \Illuminate\Support\Facades\Http::timeout(10)
                                        ->retry(1, 200)
                                        ->withOptions(['allow_redirects' => true])
                                        ->get($candidate);

                                    if ($getResponse->successful()) {
                                        return;
                                    }

                                    $fail("App URL is unreachable (HTTP {$getResponse->status()}).");

                                    return;
                                }

                                $fail("App URL is unreachable (HTTP {$headResponse->status()}).");
                            } catch (\Throwable $exception) {
                                $message = strtolower($exception->getMessage());

                                if (str_contains($message, 'curl error 28') || str_contains($message, 'timed out')) {
                                    $fail('App URL timed out.');

                                    return;
                                }

                                $fail('App URL is unreachable.');
                            }
                        };
                    })
                    ->maxLength(2048),
            ]);
    }
}
