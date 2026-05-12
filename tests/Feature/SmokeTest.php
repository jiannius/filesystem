<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Jiannius\Filesystem\Tests\TestCase;

class SmokeTest extends TestCase
{
    public function test_service_provider_boots_and_migration_runs(): void
    {
        $this->assertTrue(Schema::hasTable('files'));
    }

    public function test_routes_are_registered(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('__fs.upload'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('__fs.image'));
    }

    public function test_route_uses_default_prefix_and_middleware(): void
    {
        $upload = \Illuminate\Support\Facades\Route::getRoutes()->getByName('__fs.upload');
        $this->assertSame('__fs/upload', $upload->uri());
        $this->assertContains('web', $upload->middleware());
        $this->assertContains('auth', $upload->middleware());

        $image = \Illuminate\Support\Facades\Route::getRoutes()->getByName('__fs.image');
        $this->assertSame('__fs/img/{path}', $image->uri());
        $this->assertContains('signed', $image->middleware());
    }
}
