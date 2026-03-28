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
        $this->apiUrl = config('services.gitea.url', env('GITEA_API_URL'));
        $this->apiToken = config('services.gitea.token', env('GITEA_API_TOKEN'));
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
}
