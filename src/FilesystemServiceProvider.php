<?php

namespace Jiannius\Filesystem;

use Illuminate\Support\ServiceProvider;

class FilesystemServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fs.php', 'fs');
    }

    public function boot(): void
    {
        if (config('fs.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/fs.php' => config_path('fs.php'),
        ], 'fs-config');
    }
}
