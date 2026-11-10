<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class SubclassFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['fs.models.file' => ScopedFile::class]);
        ScopedFile::$creating = 0;
    }

    public function test_store_upload_returns_the_subclass_and_runs_its_hooks(): void
    {
        $file = ScopedFile::storeUpload(UploadedFile::fake()->image('a.jpg', 10, 10));

        $this->assertInstanceOf(ScopedFile::class, $file);
        $this->assertSame(1, ScopedFile::$creating);
    }

    public function test_store_dispatches_to_the_subclass(): void
    {
        $file = ScopedFile::store(upload: UploadedFile::fake()->image('a.jpg', 10, 10));

        $this->assertInstanceOf(ScopedFile::class, $file);
        $this->assertSame(1, ScopedFile::$creating);
    }

    public function test_store_youtube_returns_the_subclass(): void
    {
        Http::fake(['noembed.com/*' => Http::response(['title' => 'T'])]);

        $file = ScopedFile::store(youtube: 'https://youtu.be/abc123');

        $this->assertInstanceOf(ScopedFile::class, $file);
        $this->assertSame(1, ScopedFile::$creating);
    }

    public function test_store_image_url_returns_the_subclass(): void
    {
        config(['fs.allow_url_fetch' => true]);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Http::fake(['example.com/*' => Http::response($png)]);

        $file = ScopedFile::store(url: 'https://example.com/a.png');

        $this->assertInstanceOf(ScopedFile::class, $file);
        $this->assertSame(1, ScopedFile::$creating);
    }

    public function test_upload_route_creates_the_configured_subclass(): void
    {
        $this->withoutMiddleware();

        $this->postJson(route('__fs.upload'), [
            'file' => UploadedFile::fake()->image('a.jpg', 10, 10),
        ])->assertOk();

        $this->assertSame(1, ScopedFile::$creating);
    }
}

class ScopedFile extends File
{
    protected $table = 'files';

    public static int $creating = 0;

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function () {
            static::$creating++;
        });
    }
}
