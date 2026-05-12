<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class ConfigBindingsTest extends TestCase
{
    public function test_default_file_model_binding_resolves_to_package_class(): void
    {
        $this->assertSame(File::class, config('fs.models.file'));
    }

    public function test_default_routes_are_enabled(): void
    {
        $this->assertTrue(config('fs.routes.enabled'));
        $this->assertSame('__fs', config('fs.routes.prefix'));
    }

    public function test_glide_config_defaults_present(): void
    {
        $this->assertNotEmpty(config('fs.glide.cache_path'));
        $this->assertSame(2000 * 2000, config('fs.glide.max_image_size'));
    }

    public function test_cloud_disks_default_to_s3_and_do(): void
    {
        $this->assertSame(['s3', 'do'], config('fs.cloud_disks'));
    }
}
