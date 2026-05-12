<?php

namespace Jiannius\Filesystem\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Jiannius\Filesystem\FilesystemServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // The package's controllers currently extend `App\Http\Controllers\Controller`
        // and reference `App\Models\File`/`App\Models\User`, which don't exist in the
        // Testbench environment. Alias them so route registration during boot succeeds.
        // These references will be cleaned up in subsequent refactor tasks.
        if (!class_exists(\App\Http\Controllers\Controller::class, false)) {
            class_alias(\Illuminate\Routing\Controller::class, \App\Http\Controllers\Controller::class);
        }

        if (!class_exists(\App\Models\File::class, false)) {
            class_alias(\Jiannius\Filesystem\Models\File::class, \App\Models\File::class);
        }

        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [FilesystemServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('filesystems.default', 'local');
        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => storage_path('app'),
            'serve' => true,
            'throw' => false,
        ]);
    }
}
