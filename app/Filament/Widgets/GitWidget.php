<?php

namespace App\Filament\Widgets;

use App\Contracts\GitInterface;
use Filament\Widgets\Widget;

/**
 * Dashboard widget displaying the active Git instance connection status and version.
 */
class GitWidget extends Widget
{
    public string $gitUrl;
    public string $gitVersion;
    public string $gitName;
    protected string $view = 'filament.widgets.git-widget';

    protected int | string | array $columnSpan = 'full';

    /**
     * Bootstraps the widget state using the injected Git service.
     */
    public function mount(GitInterface $git): void
    {
        $this->gitUrl = rtrim($git->getRootUrl(), '/') . '/';
        $version = $git->getVersion();
        $this->gitVersion = $version !== null ? 'v' . $version : '';
        $this->gitName = ucfirst(config('services.git.default', 'gitea'));
    }

    public function getHeading(): string
    {
        return $this->gitName;
    }

    public function getDescription(): string
    {
        return $this->gitVersion;
    }
}
