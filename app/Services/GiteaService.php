<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service to interact with the configured Gitea instance via its REST API.
 * Handles authentication, data retrieval (repositories, branches, actions),
 * and implements caching strategies to avoid rate limits and improve performance.
 */
class GiteaService implements \App\Contracts\GitInterface
{
    /**
     * Default time-to-live for cached Gitea responses (repositories and branches).
     */
    protected const CACHE_TTL_MINUTES = 5;
    protected string $rootUrl;
    protected string $apiUrl;
    protected string $apiToken;

    public function __construct()
    {
        $this->rootUrl = (string) config('services.gitea.root_url');
        $this->apiUrl = (string) config('services.gitea.url');
        $this->apiToken = (string) config('services.gitea.token');
    }

    /**
     * Returns the base Gitea root URL (without API paths).
     *
     * @return string
     */
    public function getRootUrl(): string
    {
        return $this->rootUrl;
    }

    /**
     * Returns the configured personal access token used for Gitea authentication.
     *
     * @return string
     */
    public function getToken(): string
    {
        return $this->apiToken;
    }

    /**
     * Configures and returns a base HTTP client for Gitea API requests.
     * Automatically attaches the authorization token and sets connection limits.
     *
     * @return PendingRequest
     */
    public function httpClient(): PendingRequest
    {
        return Http::withToken($this->apiToken)->timeout(10)->retry(2, 200);
    }

    /**
     * Fetches a list of action runs (CI/CD workflows) for a specific repository.
     *
     * @param string $owner Repository owner username or organization.
     * @param string $repo Repository name.
     * @param array $query Optional query parameters (e.g., branch, pagination filters).
     * @return array Raw decoded JSON array containing 'workflow_runs' or equivalent. Returns empty array on failure.
     */
    public function getActionRuns(string $owner, string $repo, array $query = []): array
    {
        try {
            $response = $this->httpClient()
                ->get(rtrim($this->apiUrl, '/') . "/repos/{$owner}/{$repo}/actions/runs", $query);

            if ($response->successful()) {
                return $response->json() ?? [];
            }
        } catch (Throwable $e) {
            Log::error('Exception while fetching Gitea action runs.', ['message' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Fetches details of a specific action run by its ID.
     *
     * @param string $owner Repository owner.
     * @param string $repo Repository name.
     * @param int $runId The unique identifier of the action run.
     * @return array|null The run details payload, or null if not found/error.
     */
    public function getActionRun(string $owner, string $repo, int $runId): ?array
    {
        try {
            $response = $this->httpClient()
                ->get(rtrim($this->apiUrl, '/') . "/repos/{$owner}/{$repo}/actions/runs/{$runId}");

            if ($response->successful()) {
                return $response->json();
            }
        } catch (Throwable $e) {
            Log::error('Exception while fetching Gitea action run.', ['message' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Fetches the individual jobs (steps/tasks) associated with a specific action run.
     *
     * @param string $owner Repository owner.
     * @param string $repo Repository name.
     * @param int $runId The action run ID.
     * @return array Decoded JSON array of job payloads.
     */
    public function getRunJobs(string $owner, string $repo, int $runId): array
    {
        try {
            $response = $this->httpClient()
                ->get(rtrim($this->apiUrl, '/') . "/repos/{$owner}/{$repo}/actions/runs/{$runId}/jobs");

            if ($response->successful()) {
                return $response->json() ?? [];
            }
        } catch (Throwable $e) {
            Log::error('Exception while fetching Gitea run jobs.', ['message' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Fetches the raw text output logs for a specific job.
     * Note: Gitea may sometimes return logs as a compressed ZIP binary stream.
     *
     * @param string $owner Repository owner.
     * @param string $repo Repository name.
     * @param int $jobId The unique identifier of the job.
     * @return string|null The raw log content (text or binary zip), or null on failure.
     */
    public function getJobLog(string $owner, string $repo, int $jobId): ?string
    {
        try {
            // Extended timeout for potentially large log downloads
            $response = $this->httpClient()
                ->timeout(20)
                ->get(rtrim($this->apiUrl, '/') . "/repos/{$owner}/{$repo}/actions/jobs/{$jobId}/logs");

            if ($response->successful()) {
                return $response->body();
            }
        } catch (Throwable $e) {
            Log::error('Exception while fetching Gitea job log.', ['message' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Fetches the file or directory metadata from the repository tree.
     *
     * @param string $owner Repository owner.
     * @param string $repo Repository name.
     * @param string $path Path inside the repository.
     * @param string $ref The branch name or commit SHA to inspect.
     * @return array|null The decoded content metadata or null on failure.
     */
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
            Log::error('Exception while fetching Gitea repository content.', ['message' => $e->getMessage()]);
        }

        return null;
    }



    /**
     * Fetches a list of repositories accessible to the authenticated user.
     * Results are cached briefly to speed up UI dropdown rendering.
     *
     * @return array Decoded JSON array of repository data payloads.
     */
    public function getRepositories(): array
    {
        if (empty($this->apiUrl) || empty($this->apiToken)) {
            Log::warning('Gitea API URL or Token is missing.');
            return [];
        }

        $cacheKey = 'gitea.repositories';

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL_MINUTES), function (): array {
            try {
                $response = $this->httpClient()
                    ->get(rtrim($this->apiUrl, '/') . '/user/repos');

                if ($response->successful()) {
                    return $response->json();
                }

                Log::error('Failed to fetch Gitea repositories.', ['status' => $response->status(), 'response' => $response->body()]);
            } catch (Throwable $e) {
                Log::error('Exception while fetching Gitea repositories.', ['message' => $e->getMessage()]);
            }

            return [];
        });
    }

    /**
     * Resolves repository owner and name from a given clone URL, then fetches its branches.
     *
     * @param string $cloneUrl The HTTP clone URL of the repository.
     * @param bool $useCache Whether to leverage the cache.
     * @return array<int, string> A flat list of branch names.
     */
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

    /**
     * Public proxy to fetch repository branches, optionally using cache.
     *
     * @param string $owner
     * @param string $repo
     * @param bool $useCache
     * @return array<int, string>
     */
    public function getBranches(string $owner, string $repo, bool $useCache = true): array
    {
        if (empty($this->apiUrl) || empty($this->apiToken)) {
            return [];
        }

        if (! $useCache) {
            return $this->fetchBranches($owner, $repo);
        }

        $cacheKey = sprintf('gitea.branches.%s.%s', $owner, $repo);

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL_MINUTES), function () use ($owner, $repo): array {
            return $this->fetchBranches($owner, $repo);
        });
    }

    /**
     * Executes the API request to fetch repository branches and extracts their names.
     *
     * @param string $owner
     * @param string $repo
     * @return array<int, string>
     */
    protected function fetchBranches(string $owner, string $repo): array
    {
        try {
            $response = $this->httpClient()
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
                fn ($branch) => is_array($branch) ? ($branch['name'] ?? null) : null,
                $branches
            )));
        } catch (Throwable $e) {
            Log::error('Exception while fetching Gitea branches.', [
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
            Log::error('Exception while fetching Gitea user.', ['message' => $e->getMessage()]);
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

            // If conflict (repo exists), we can also return an array indicating it exists
            if ($response->status() === 409) {
                return ['error' => 'Conflict', 'status' => 409, 'message' => "Repository {$name} already exists."];
            }
        } catch (Throwable $e) {
            Log::error('Exception while creating Gitea repository.', ['message' => $e->getMessage()]);
        }

        return null;
    }
}
