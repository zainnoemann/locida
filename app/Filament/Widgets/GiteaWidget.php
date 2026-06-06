<?php

namespace App\Filament\Widgets;

use App\Services\GiteaService;
use Filament\Widgets\Widget;

/**
 * Dashboard widget displaying the active Gitea instance connection status and version.
 * Serves as a quick health check for the core Git integration.
 */
class GiteaWidget extends Widget
{
    public string $giteaUrl;
    public string $giteaVersion;
    protected string $view = 'filament.widgets.gitea-widget';

    protected int | string | array $columnSpan = 'full';

    /**
     * Bootstraps the widget state using the injected Gitea service.
     */
    public function mount(GiteaService $gitea): void
    {
        $this->giteaUrl = rtrim($gitea->getRootUrl(), '/') . '/';
        $version = $gitea->getVersion();
        $this->giteaVersion = $version !== null ? 'v' . $version : '';
    }

    public function getHeading(): string
    {
        return 'Gitea';
    }

    public function getDescription(): string
    {
        return $this->giteaVersion;
    }
}
