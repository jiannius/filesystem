<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class ProductionGuardTest extends TestCase
{
    public function test_deleting_production_s3_file_in_non_prod_throws(): void
    {
        $file = File::create([
            'name' => 'prod.jpg',
            'mime' => 'image/jpeg',
            'disk' => 's3',
            'path' => 'fake/prod.jpg',
            'env'  => 'production',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Do not delete production file/');

        $file->delete();
    }

    public function test_deleting_production_local_file_in_non_prod_does_not_throw(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');

        $file = File::create([
            'name' => 'prod.jpg',
            'mime' => 'image/jpeg',
            'disk' => 'local',
            'path' => null,
            'env'  => 'production',
        ]);

        $file->delete();
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_cloud_disks_list_is_configurable(): void
    {
        config(['fs.cloud_disks' => ['custom']]);

        $file = File::create([
            'name' => 'prod.jpg',
            'mime' => 'image/jpeg',
            'disk' => 'custom',
            'path' => 'x/prod.jpg',
            'env'  => 'production',
        ]);

        $this->expectException(\Exception::class);
        $file->delete();
    }
}
