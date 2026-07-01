<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Contracts\GitInterface::class, function ($app) {
            $git = config('services.git.default', 'gitea');

            if ($git === 'github') {
                return new \App\Services\GitHubService();
            }

            return new \App\Services\GiteaService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
