<?php

namespace App\Services;

use App\Contracts\GitInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service to interact with the configured GitHub instance via its REST API.
 * Handles authentication, data retrieval (repositories, branches, actions),
 * and implements caching strategies to avoid rate limits and improve performance.
 */
class GitHubService implements GitInterface
{
    /**
     * Default time-to-live for cached responses.
     */
    protected const CACHE_TTL_MINUTES = 5;
    protected string $rootUrl;
    protected string $apiUrl;
    protected string $apiToken;

    public function __construct()
    {
        $this->rootUrl = (string) config('services.github.root_url');
        $this->apiUrl = (string) config('services.github.url');
        $this->apiToken = (string) config('services.github.token');
    }

    public function getRootUrl(): string
    {
        return $this->rootUrl;
    }

    public function getToken(): string
    {
        return $this->apiToken;
    }

    /**
     * Configures and returns a base HTTP client for GitHub API requests.
     */
    public function httpClient(): PendingRequest
    {
        return Http::withToken($this->apiToken)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(10)->retry(2, 200);
    }

    public function getActionRuns(string $owner, string $repo, array $query = []): array
    {
        try {
            $response = $this->httpClient()
                ->get(rtrim($this->apiUrl, '/') . "/repos/{$owner}/{$repo}/actions/runs", $query);

            if ($response->successful()) {
                return $response->json() ?? [];
            }
        } catch (Throwable $e) {
            Log::error('Exception while fetching GitHub action runs.', ['message' => $e->getMessage()]);
        }

        return [];
    }

    public function getActionRun(string $owner, string $repo, int $runId): ?array
    {
        try {
            $response = $this->httpClient()
                ->get(rtrim($this->apiUrl, '/') . "/repos/{$owner}/{$repo}/actions/runs/{$runId}");

            if ($response->successful()) {
                return $response->json();
            }
        } catch (Throwable $e) {
            Log::error('Exception while fetching GitHub action run.', ['message' => $e->getMessage()]);
        }

        return null;
    }

    public function getRunJobs(string $owner, string $repo, int $runId): array
    {
        try {
            $response = $this->httpClient()
                ->get(rtrim($this->apiUrl, '/') . "/repos/{$owner}/{$repo}/actions/runs/{$runId}/jobs");

            if ($response->successful()) {
                return $response->json() ?? [];
            }
        } catch (Throwable $e) {
            Log::error('Exception while fetching GitHub run jobs.', ['message' => $e->getMessage()]);
        }

        return [];
    }

    public function getJobLog(string $owner, string $repo, int $jobId): ?string
    {
        try {
            $response = $this->httpClient()
                ->timeout(20)
                ->get(rtrim($this->apiUrl, '/') . "/repos/{$owner}/{$repo}/actions/jobs/{$jobId}/logs");

            if ($response->successful()) {
                return $response->body();
            }
        } catch (Throwable $e) {
            Log::error('Exception while fetching GitHub job log.', ['message' => $e->getMessage()]);
        }

        return null;
    }

    public function getRepositoryContent(string $owner, string $repo, string $path, string $ref): ?array
    {
        try {
            $response = $this->httpClient()
                ->get(rtrim($this->apiUrl, '/') . "/repos/{$owner}/{$repo}/contents/{$path}", [
                    'ref' => $ref,
                ]);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (Throwable $e) {
            Log::error('Exception while fetching GitHub repository content.', ['message' => $e->getMessage()]);
        }

        return null;
    }



    public function getRepositories(): array
    {
        if (empty($this->apiUrl) || empty($this->apiToken)) {
            Log::warning('GitHub API URL or Token is missing.');
            return [];
        }

        $cacheKey = 'github.repositories';

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL_MINUTES), function (): array {
            try {
                // Fetch user's repos. Note: pagination is usually required for GitHub if many repos.
                $response = $this->httpClient()
                    ->get(rtrim($this->apiUrl, '/') . '/user/repos', ['per_page' => 100]);

                if ($response->successful()) {
                    return $response->json();
                }

                Log::error('Failed to fetch GitHub repositories.', ['status' => $response->status(), 'response' => $response->body()]);
            } catch (Throwable $e) {
                Log::error('Exception while fetching GitHub repositories.', ['message' => $e->getMessage()]);
            }

            return [];
        });
    }

    public function getBranchesByCloneUrl(string $cloneUrl, bool $useCache = true): array
    {
        $repo = collect($this->getRepositories())
            ->first(fn ($item) => is_array($item) && ($item['clone_url'] ?? null) === $cloneUrl);

        if (! is_array($repo)) {
            return [];
        }

        $fullName = (string) ($repo['full_name'] ?? '');
        [$owner, $name] = explode('/', $fullName, 2) + [null, null];

        if (empty($owner) || empty($name)) {
            return [];
        }

        return $this->getBranches($owner, $name, $useCache);
    }

    public function getBranches(string $owner, string $repo, bool $useCache = true): array
    {
        if (empty($this->apiUrl) || empty($this->apiToken)) {
            return [];
        }

        if (! $useCache) {
            return $this->fetchBranches($owner, $repo);
        }

        $cacheKey = sprintf('github.branches.%s.%s', $owner, $repo);

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL_MINUTES), function () use ($owner, $repo): array {
            return $this->fetchBranches($owner, $repo);
        });
    }

    protected function fetchBranches(string $owner, string $repo): array
    {
        try {
            $response = $this->httpClient()
                ->get(rtrim($this->apiUrl, '/') . "/repos/{$owner}/{$repo}/branches", ['per_page' => 100]);

            if (! $response->successful()) {
                Log::error('Failed to fetch GitHub branches.', [
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
                fn ($branch) => is_array($branch) ? ($branch['name'] ?? null) : null,
                $branches
            )));
        } catch (Throwable $e) {
            Log::error('Exception while fetching GitHub branches.', [
                'owner' => $owner,
                'repo' => $repo,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function getAuthenticatedUser(): ?array
    {
        try {
            $response = $this->httpClient()
                ->get(rtrim($this->apiUrl, '/') . '/user');

            if ($response->successful()) {
                return $response->json();
            }
        } catch (Throwable $e) {
            Log::error('Exception while fetching GitHub user.', ['message' => $e->getMessage()]);
        }

        return null;
    }

    public function createRepository(string $name, bool $private = true): ?array
    {
        try {
            $response = $this->httpClient()
                ->post(rtrim($this->apiUrl, '/') . '/user/repos', [
                    'name' => $name,
                    'private' => $private,
                    'auto_init' => false
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            return ['error' => 'Conflict', 'status' => $response->status(), 'message' => $response->body()];
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'status code 422') || str_contains($e->getMessage(), 'status code 409')) {
                return ['error' => 'Conflict', 'status' => 422, 'message' => 'Repository already exists.'];
            }
            Log::error('Exception while creating GitHub repository.', ['message' => $e->getMessage()]);
            return ['error' => 'Exception', 'message' => $e->getMessage()];
        }

        return null;
    }
}
