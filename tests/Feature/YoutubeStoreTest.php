<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class YoutubeStoreTest extends TestCase
{
    public function test_storeYoutube_creates_record_with_noembed_metadata(): void
    {
        Http::fake([
            'noembed.com/*' => Http::response([
                'title'         => 'Test Video Title',
                'thumbnail_url' => 'https://example.com/thumb.jpg',
            ], 200),
        ]);

        $file = File::storeYoutube('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $this->assertNotNull($file);
        $this->assertSame('Test Video Title', $file->name);
        $this->assertSame('youtube', $file->mime);
        $this->assertSame('dQw4w9WgXcQ', $file->data['vid']);
        $this->assertSame('https://example.com/thumb.jpg', $file->data['thumbnail']);
        $this->assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ', $file->data['embed']);
    }

    public function test_storeYoutube_degrades_gracefully_when_noembed_fails(): void
    {
        Http::fake([
            'noembed.com/*' => Http::response(null, 500),
        ]);

        $file = File::storeYoutube('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $this->assertNotNull($file);
        $this->assertSame('dQw4w9WgXcQ', $file->name); // falls back to vid
        $this->assertSame('youtube', $file->mime);
    }

    public function test_storeYoutube_returns_null_for_non_youtube_url(): void
    {
        $this->assertNull(File::storeYoutube('https://example.com/notvideo'));
    }
}
