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
}
