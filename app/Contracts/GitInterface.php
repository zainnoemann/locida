<?php

namespace App\Contracts;

interface GitInterface
{
    /**
     * Returns the base root URL of the Git.
     *
     * @return string
     */
    public function getRootUrl(): string;

    /**
     * Returns the configured personal access token used for authentication.
     *
     * @return string
     */
    public function getToken(): string;

    /**
     * Fetches a list of action runs (CI/CD workflows) for a specific repository.
     *
     * @param string $owner Repository owner username or organization.
     * @param string $repo Repository name.
     * @param array $query Optional query parameters.
     * @return array
     */
    public function getActionRuns(string $owner, string $repo, array $query = []): array;

    /**
     * Fetches details of a specific action run by its ID.
     *
     * @param string $owner Repository owner.
     * @param string $repo Repository name.
     * @param int $runId The unique identifier of the action run.
     * @return array|null
     */
    public function getActionRun(string $owner, string $repo, int $runId): ?array;

    /**
     * Fetches the individual jobs (steps/tasks) associated with a specific action run.
     *
     * @param string $owner Repository owner.
     * @param string $repo Repository name.
     * @param int $runId The action run ID.
     * @return array
     */
    public function getRunJobs(string $owner, string $repo, int $runId): array;

    /**
     * Fetches the raw text output logs for a specific job.
     *
     * @param string $owner Repository owner.
     * @param string $repo Repository name.
     * @param int $jobId The unique identifier of the job.
     * @return string|null
     */
    public function getJobLog(string $owner, string $repo, int $jobId): ?string;

    /**
     * Fetches the file or directory metadata from the repository tree.
     *
     * @param string $owner Repository owner.
     * @param string $repo Repository name.
     * @param string $path Path inside the repository.
     * @param string $ref The branch name or commit SHA to inspect.
     * @return array|null
     */
    public function getRepositoryContent(string $owner, string $repo, string $path, string $ref): ?array;



    /**
     * Fetches a list of repositories accessible to the authenticated user.
     *
     * @return array
     */
    public function getRepositories(): array;

    /**
     * Resolves repository owner and name from a given clone URL, then fetches its branches.
     *
     * @param string $cloneUrl The HTTP clone URL of the repository.
     * @param bool $useCache Whether to leverage the cache.
     * @return array<int, string>
     */
    public function getBranchesByCloneUrl(string $cloneUrl, bool $useCache = true): array;

    /**
     * Public proxy to fetch repository branches.
     *
     * @param string $owner
     * @param string $repo
     * @param bool $useCache
     * @return array<int, string>
     */
    public function getBranches(string $owner, string $repo, bool $useCache = true): array;

    /**
     * Fetches the authenticated user profile data.
     *
     * @return array|null
     */
    public function getAuthenticatedUser(): ?array;

    /**
     * Creates a new repository for the authenticated user.
     *
     * @param string $name Repository name
     * @param bool $private Whether the repository should be private
     * @return array|null
     */
    public function createRepository(string $name, bool $private = true): ?array;
}
