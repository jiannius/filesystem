<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class UrlFetchTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_url_fetch_is_off_by_default(): void
    {
        $this->assertFalse(config('fs.allow_url_fetch'));
    }

    public function test_upload_route_refuses_urls_and_fetches_nothing_when_disabled(): void
    {
        Http::fake();
        $this->withoutMiddleware();

        $this->postJson(route('__fs.upload'), ['url' => ['http://169.254.169.254/latest/meta-data/']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');

        Http::assertNothingSent();
        $this->assertSame(0, File::query()->count());
    }

    public function test_store_image_url_throws_and_fetches_nothing_when_disabled(): void
    {
        Http::fake();

        try {
            File::storeImageUrl('http://127.0.0.1/a.png');
            $this->fail('Expected ValidationException');
        } catch (ValidationException) {
        }

        Http::assertNothingSent();
        $this->assertSame(0, File::query()->count());
    }

    public function test_upload_route_fetches_urls_when_enabled(): void
    {
        config(['fs.allow_url_fetch' => true]);
        Http::fake(['example.com/*' => Http::response(base64_decode(self::PNG))]);
        $this->withoutMiddleware();

        $this->postJson(route('__fs.upload'), ['url' => ['https://example.com/a.png']])->assertOk();

        $this->assertSame(1, File::query()->count());
        $this->assertSame('image/png', File::query()->first()->mime);
    }

    public function test_get_base64_reads_disk_files_from_the_disk_not_http(): void
    {
        Storage::fake('local');
        Http::fake();

        $file = File::storeUpload(UploadedFile::fake()->image('a.png', 4, 4));
        $expected = base64_encode(Storage::disk('local')->get($file->path));

        $this->assertSame('data:image/png;base64,'.$expected, $file->getBase64());
        Http::assertNothingSent();
    }

    public function test_get_base64_does_not_fetch_external_url_when_disabled(): void
    {
        Http::fake();

        $file = File::create(['name' => 'x', 'mime' => 'image/png', 'url' => 'http://10.0.0.1/a.png']);

        $this->assertNull($file->getBase64());
        Http::assertNothingSent();
    }
}
