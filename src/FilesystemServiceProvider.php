<?php

namespace Jiannius\Filesystem;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class FilesystemServiceProvider extends ServiceProvider
{
    // register
    public function register() : void
    {
        //
    }

    // boot
    public function boot() : void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filesystem');

        // register commands
        $this->commands([
            \Jiannius\Filesystem\Commands\OptimizeCommand::class,
        ]);

        // intervention/image
        $this->app->bind('image', function() {
            if (extension_loaded('imagick')) return new \Intervention\Image\ImageManager(new \Intervention\Image\Drivers\Imagick\Driver());
            if (extension_loaded('gd')) return new \Intervention\Image\ImageManager(new \Intervention\Image\Drivers\Gd\Driver());
        });

        // just so we can use <filesystem:component/>
        $compiler = new \Jiannius\Filesystem\Services\TagCompiler(
            app('blade.compiler')->getClassComponentAliases(),
            app('blade.compiler')->getClassComponentNamespaces(),
            app('blade.compiler')
        );

        app()->bind('filesystem.compiler', fn () => $compiler);

        app('blade.compiler')->precompiler(function ($in) use ($compiler) {
            return $compiler->compile($in);
        });

        // digital ocean spaces config
        config(['filesystems.disks.do' => [
            'driver' => 's3',
            'key' => env('DO_SPACES_KEY'),
            'secret' => env('DO_SPACES_SECRET'),
            'region' => env('DO_SPACES_REGION'),
            'bucket' => env('DO_SPACES_BUCKET'),
            'folder' => env('DO_SPACES_FOLDER'),
            'endpoint' => env('DO_SPACES_ENDPOINT'),
            'use_path_style_endpoint' => false,
        ]]);

        // blade components
        Blade::anonymousComponentPath(__DIR__.'/../components', 'filesystem');

        // livewire components
        \Livewire\Livewire::component('filesystem.edit', \Jiannius\Filesystem\Livewire\Edit::class);
        \Livewire\Livewire::component('filesystem.manager', \Jiannius\Filesystem\Livewire\Manager::class);
    }
}