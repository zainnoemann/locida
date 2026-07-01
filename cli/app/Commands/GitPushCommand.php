<?php

namespace App\Commands;

use App\Contracts\GitInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GitPushCommand extends Command
{
    protected $signature = 'push {path? : Target folder path. If empty, the current directory will be used.}';
    protected $description = 'Automatically create a repository on the active Git provider via REST API and push code from the target folder.';

    private GitInterface $git;
    private string $gitName;
    private string $gitUrl;
    private string $gitToken;

    public function handle(GitInterface $git): int
    {
        $this->git = $git;
        
        $targetPath = $this->validateTargetDirectory();
        if (!$targetPath) return Command::FAILURE;

        $repoName = Str::slug(basename($targetPath));

        if (!$this->loadGitConfiguration($repoName, $targetPath)) return Command::FAILURE;
        
        $username = $this->authenticateUser();
        if (!$username) return Command::FAILURE;

        if (!$this->createRemoteRepository($repoName)) return Command::FAILURE;

        $this->initializeAndCommitLocalRepository($targetPath);

        return $this->pushToRemote($username, $repoName, $targetPath);
    }

    /**
     * Validates the provided target directory path.
     *
     * @return string|null The resolved target path, or null if invalid.
     */
    private function validateTargetDirectory(): ?string
    {
        $targetPath = realpath($this->argument('path') ?: getcwd());

        if (!$targetPath || !is_dir($targetPath)) {
            $this->logError("Invalid target directory: " . ($this->argument('path') ?: getcwd()));
            return null;
        }

        return $targetPath;
    }

    /**
     * Loads and validates the Git configuration settings.
     *
     * @param string $repoName The sanitized repository name.
     * @param string $targetPath The path of the local repository.
     * @return bool True if configuration is valid, false otherwise.
     */
    private function loadGitConfiguration(string $repoName, string $targetPath): bool
    {
        // For CLI, we can fallback to reading from a global config or local .env
        $this->gitName = ucfirst(config('services.git.default', 'gitea'));
        $this->gitUrl = rtrim($this->git->getRootUrl(), '/');
        $this->gitToken = $this->git->getToken();

        $this->logInfo("Pushing {$repoName} to {$this->gitName}");
        $this->logInfo("Target directory: {$targetPath}");

        if (empty($this->gitUrl) || empty($this->gitToken)) {
            $this->logError("API URL or Token configuration for {$this->gitName} is missing in .env");
            return false;
        }

        return true;
    }

    /**
     * Authenticates the user via the Git API and retrieves their username.
     *
     * @return string|null The authenticated username, or null on failure.
     */
    private function authenticateUser(): ?string
    {
        $this->logInfo("Fetching {$this->gitName} account information");
        
        $user = $this->git->getAuthenticatedUser();

        if (!$user) {
            $this->logError("Failed to fetch user profile. Ensure the Token is valid.");
            return null;
        }

        $username = $user['login'] ?? null;
        if (!$username) {
            $this->logError("Unable to find username in the profile.");
            return null;
        }

        $this->logInfo("Authenticated as: {$username}");
        return $username;
    }

    /**
     * Creates a new public repository on the remote Git server.
     *
     * @param string $repoName The name of the repository to create.
     * @return bool True on success or if the repository already exists, false on failure.
     */
    private function createRemoteRepository(string $repoName): bool
    {
        $this->logInfo("Creating public repository on {$this->gitName} via API");
        
        $repo = $this->git->createRepository($repoName, false);

        if ($repo && isset($repo['status']) && in_array($repo['status'], [409, 422])) {
            $this->logWarn("Repository '{$repoName}' already exists on {$this->gitName}, proceeding");
            return true;
        } 
        
        if ($repo && !isset($repo['error'])) {
            $this->logInfo("Repository successfully created");
            return true;
        }

        $errorMessage = $repo['message'] ?? 'Unknown error';
        $this->logError("Failed to create repository. {$errorMessage}");
        return false;
    }

    /**
     * Initializes the local git repository and commits all files if not already done.
     *
     * @param string $targetPath The local path to initialize.
     * @return void
     */
    private function initializeAndCommitLocalRepository(string $targetPath): void
    {
        chdir($targetPath);

        if (!is_dir('.git')) {
            $this->logInfo("Initializing local git repository");
            exec('git init');
            exec('git checkout -b main');
        } else {
            $this->logInfo("Local git repository already initialized");
        }

        $this->logInfo("Staging files and creating commit");
        exec('git add .');
        exec('git commit -m "Initial commit via Locida CLI"');
    }

    /**
     * Configures the remote origin and pushes the local repository to the remote server.
     *
     * @param string $username The authenticated username.
     * @param string $repoName The repository name.
     * @param string $targetPath The local repository path.
     * @return int The command exit code (Command::SUCCESS or Command::FAILURE).
     */
    private function pushToRemote(string $username, string $repoName, string $targetPath): int
    {
        $remoteUrl = $this->buildRemoteUrl($username, $repoName);
        $pushUrl = $this->buildPushUrl($username, $remoteUrl);

        $this->logInfo("Configuring remote origin");
        exec('git remote remove origin 2> NUL'); 
        exec("git remote add origin {$remoteUrl}");

        $this->logInfo("Pushing code to {$this->gitName}");
        
        $output = [];
        $returnVar = 0;
        exec("git push -u \"{$pushUrl}\" main 2>&1", $output, $returnVar);

        if ($returnVar !== 0) {
            $this->logError("Failed to push code. Error message:");
            foreach ($output as $line) {
                $this->logWarn(str_replace($pushUrl, $remoteUrl, $line));
            }
            return Command::FAILURE;
        }

        $this->logInfo("Finished! Code successfully pushed to: {$remoteUrl}");
        exec("git remote set-url origin {$remoteUrl}");

        return Command::SUCCESS;
    }

    /**
     * Builds the public remote URL for the repository.
     *
     * @param string $username The authenticated username.
     * @param string $repoName The repository name.
     * @return string The remote clone URL.
     */
    private function buildRemoteUrl(string $username, string $repoName): string
    {
        $hostUrl = str_replace(['http://', 'https://'], '', $this->gitUrl);
        $remoteUrl = "https://{$hostUrl}/{$username}/{$repoName}.git";
        
        if (str_contains($this->gitUrl, 'localhost') || str_contains($this->gitUrl, '127.0.0') || config('services.git.default') === 'gitea') {
             $remoteUrl = "{$this->gitUrl}/{$username}/{$repoName}.git";
        }

        return $remoteUrl;
    }

    /**
     * Builds the URL containing credentials for pushing to the remote repository.
     *
     * @param string $username The authenticated username.
     * @param string $remoteUrl The public remote URL.
     * @return string The authenticated push URL.
     */
    private function buildPushUrl(string $username, string $remoteUrl): string
    {
        return str_replace(
            ['http://', 'https://'], 
            ["http://{$username}:{$this->gitToken}@", "https://{$username}:{$this->gitToken}@"], 
            $remoteUrl
        );
    }

    private function logInfo(string $message): void
    {
        $this->line("<fg=cyan;options=bold>INFO</>  {$message}");
    }

    private function logError(string $message): void
    {
        $this->line("<fg=red;options=bold>ERROR</> {$message}");
    }

    private function logWarn(string $message): void
    {
        $this->line("<fg=yellow;options=bold>WARN</>  {$message}");
    }
}
