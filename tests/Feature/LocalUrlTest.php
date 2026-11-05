<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Illuminate\Support\Facades\File as FS;
use Illuminate\Support\Facades\Storage;
use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class LocalUrlTest extends TestCase
{
    protected string $root;
    protected string $publicRoot;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->root = sys_get_temp_dir().'/fs-local-'.uniqid();
        $this->publicRoot = sys_get_temp_dir().'/fs-public-'.uniqid();

        // Mirrors a Laravel 11+ host: a private, self-serving `local` disk. serve=true must
        // be set before boot so the package's serve route is registered.
        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => $this->root,
            'serve' => true,
            'throw' => false,
        ]);

        // A non-serving, public-style disk symlinked (conceptually) to public/storage.
        $app['config']->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => $this->publicRoot,
            'url' => 'https://cdn.example.test/storage',
            'visibility' => 'public',
            'throw' => false,
        ]);
    }

    protected function tearDown(): void
    {
        FS::deleteDirectory($this->root);
        FS::deleteDirectory($this->publicRoot);

        parent::tearDown();
    }

    protected function makeFile(string $disk, string $path = 'branch/note.txt'): File
    {
        Storage::disk($disk)->put($path, 'hello world');

        return File::create([
            'name' => 'note.txt',
            'mime' => 'text/plain',
            'disk' => $disk,
            'path' => $path,
        ]);
    }

    public function test_local_serve_disk_gets_a_signed_url_that_returns_the_file(): void
    {
        $file = $this->makeFile('local');

        $this->assertStringContainsString('signature=', $file->url);
        $this->assertStringContainsString('expires=', $file->url);

        $response = $this->get($file->url)->assertOk();
        $this->assertSame('hello world', $response->streamedContent());
    }

    public function test_unsigned_storage_url_is_still_rejected(): void
    {
        $this->makeFile('local');

        $this->get('/storage/branch/note.txt')->assertForbidden();
    }

    public function test_public_style_local_disk_gets_the_plain_disk_url(): void
    {
        $file = $this->makeFile('public');

        $this->assertSame('https://cdn.example.test/storage/branch/note.txt', $file->url);
    }

    public function test_local_signed_url_is_stable_within_a_window_and_changes_in_the_next(): void
    {
        $file = $this->makeFile('local');

        $this->travelTo(now()->startOfDay()->addDays(7)->addHour());
        $first = $file->url;
        parse_str(parse_url($first, PHP_URL_QUERY), $params);
        $expires = (int) $params['expires'];
        $span = 7 * 86400;

        $this->assertSame(0, $expires % $span);
        $this->assertGreaterThanOrEqual(now()->timestamp + $span, $expires);

        $this->assertSame($first, File::find($file->id)->url);

        $this->travelTo(now()->setTimestamp($expires - 2 * $span)->addSecond());
        $this->assertSame($first, $file->url);
        $this->travelTo(now()->setTimestamp($expires - $span));
        $this->assertSame($first, $file->url);

        $this->travelTo(now()->setTimestamp($expires - $span + 1));
        $this->assertNotSame($first, $file->url);
    }

    public function test_window_follows_the_configured_ttl_and_falls_back_when_zero(): void
    {
        $file = $this->makeFile('local');

        config(['fs.image_url_default_ttl_days' => 14]);
        parse_str(parse_url($file->url, PHP_URL_QUERY), $params);
        $this->assertSame(0, (int) $params['expires'] % (14 * 86400));

        config(['fs.image_url_default_ttl_days' => 0]);
        parse_str(parse_url(File::find($file->id)->url, PHP_URL_QUERY), $params);
        $this->assertSame(0, (int) $params['expires'] % (7 * 86400));
    }

    public function test_file_without_a_path_has_no_url(): void
    {
        $file = File::create(['name' => 'x', 'mime' => 'text/plain', 'disk' => 'local']);

        $this->assertNull($file->url);
    }

    public function test_cloud_disk_url_behaviour_is_unchanged(): void
    {
        config(['filesystems.disks.s3' => [
            'driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'us-east-1',
            'bucket' => 'b', 'url' => 'https://bucket.example.test',
        ]]);

        $file = File::create([
            'name' => 'a.jpg', 'mime' => 'image/jpeg', 'disk' => 's3',
            'path' => 'a/b.jpg', 'visibility' => 'public',
        ]);

        $this->assertSame('https://bucket.example.test/a/b.jpg', $file->url);

        $private = File::create([
            'name' => 'a.jpg', 'mime' => 'image/jpeg', 'disk' => 's3',
            'path' => 'a/b.jpg', 'visibility' => 'private',
        ]);

        $this->assertStringContainsString('X-Amz-Signature', $private->url);
    }
}
