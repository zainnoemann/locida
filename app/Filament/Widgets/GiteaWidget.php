<?php

namespace App\Filament\Widgets;

use App\Services\GiteaService;
use Filament\Widgets\Widget;

class GiteaWidget extends Widget
{
    protected string $view = 'filament.widgets.gitea-widget';

    protected int | string | array $columnSpan = 'full';

    public string $giteaUrl;
    public string $giteaVersion;

    public function mount(): void
    {
        $this->giteaUrl = rtrim((string) config('services.gitea.root_url'), '/') . '/';
        $version = app(GiteaService::class)->getVersion();
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
