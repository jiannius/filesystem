<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class ImageRouteTest extends TestCase
{
    public function test_signed_image_url_returns_image_content(): void
    {
        Storage::fake('local');

        $upload = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $stored = $upload->storeAs('test', 'photo.jpg', 'local');

        $file = File::create([
            'name'   => 'photo.jpg',
            'mime'   => 'image/jpeg',
            'disk'   => 'local',
            'path'   => $stored,
            'width'  => 100,
            'height' => 100,
        ]);

        $signedUrl = URL::temporarySignedRoute('__fs.image', now()->addMinutes(5), [
            'path' => $file->path,
        ]);

        $response = $this->get($signedUrl);

        $response->assertOk();
        $this->assertStringContainsString('image/', $response->headers->get('content-type'));
    }

    public function test_unsigned_image_url_is_rejected(): void
    {
        $response = $this->get('/__fs/img/test/photo.jpg');
        $response->assertStatus(403);
    }

    public function test_getImageUrl_uses_configured_default_ttl(): void
    {
        config(['fs.image_url_default_ttl_days' => 14]);

        $file = File::create([
            'name' => 'x.jpg',
            'mime' => 'image/jpeg',
            'disk' => 'local',
            'path' => 'x.jpg',
        ]);

        $url = $file->getImageUrl();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $expectedExpiry = now()->addDays(14)->timestamp;
        $this->assertEqualsWithDelta($expectedExpiry, (int) $params['expires'], 60);
    }
}
