<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Jiannius\Filesystem\Tests\TestCase;

class RoutesDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('fs.routes.enabled', false);
    }

    public function test_routes_are_not_registered_when_disabled(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('__fs.upload'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('__fs.image'));
    }
}
