<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GiteaService
{
    protected string $apiUrl;
    protected string $apiToken;

    public function __construct()
    {
        $this->apiUrl = (string) config('services.gitea.url');
        $this->apiToken = (string) config('services.gitea.token');
    }

    /**
     * Fetch user's repositories from Gitea.
     *
     * @return array
     */
    public function getRepositories(): array
    {
        if (empty($this->apiUrl) || empty($this->apiToken)) {
            Log::warning('Gitea API URL or Token is missing.');
            return [];
        }

        $cacheKey = 'gitea.repositories';

        return Cache::remember($cacheKey, now()->addMinutes(5), function (): array {
            try {
                $response = Http::withToken($this->apiToken)
                    ->timeout(10)
                    ->retry(2, 200)
                    ->get(rtrim($this->apiUrl, '/') . '/user/repos');

                if ($response->successful()) {
                    return $response->json();
                }

                Log::error('Failed to fetch Gitea repositories.', ['status' => $response->status(), 'response' => $response->body()]);
            } catch (\Exception $e) {
                Log::error('Exception while fetching Gitea repositories.', ['message' => $e->getMessage()]);
            }

            return [];
        });
    }

    /**
     * Fetch branch names for a repository selected by clone URL.
     *
     * @return array<int, string>
     */
    public function getBranchesByCloneUrl(string $cloneUrl): array
    {
        $repo = collect($this->getRepositories())
            ->first(fn($item) => is_array($item) && ($item['clone_url'] ?? null) === $cloneUrl);

        if (! is_array($repo)) {
            return [];
        }

        $fullName = (string) ($repo['full_name'] ?? '');
        [$owner, $name] = explode('/', $fullName, 2) + [null, null];

        if (empty($owner) || empty($name)) {
            return [];
        }

        return $this->getBranches($owner, $name);
    }

    /**
     * Fetch repository branches from Gitea API.
     *
     * @return array<int, string>
     */
    public function getBranches(string $owner, string $repo): array
    {
        if (empty($this->apiUrl) || empty($this->apiToken)) {
            return [];
        }

        $cacheKey = sprintf('gitea.branches.%s.%s', $owner, $repo);

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($owner, $repo): array {
            try {
                $response = Http::withToken($this->apiToken)
                    ->timeout(10)
                    ->retry(2, 200)
                    ->get(rtrim($this->apiUrl, '/') . "/repos/{$owner}/{$repo}/branches");

                if (! $response->successful()) {
                    Log::error('Failed to fetch Gitea branches.', [
                        'owner' => $owner,
                        'repo' => $repo,
                        'status' => $response->status(),
                    ]);

                    return [];
                }

                $branches = $response->json();

                if (! is_array($branches)) {
                    return [];
                }

                return array_values(array_filter(array_map(
                    fn($branch) => is_array($branch) ? ($branch['name'] ?? null) : null,
                    $branches
                )));
            } catch (\Throwable $e) {
                Log::error('Exception while fetching Gitea branches.', [
                    'owner' => $owner,
                    'repo' => $repo,
                    'message' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }
}
