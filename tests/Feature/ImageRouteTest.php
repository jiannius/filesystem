<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class ImageRouteTest extends TestCase
{
    protected string $glideCache;

    protected function setUp(): void
    {
        parent::setUp();

        // A private Glide cache per test, so a derivative cached by another test cannot
        // satisfy a request whose blob is meant to be missing (or prove Glide was skipped).
        $this->glideCache = sys_get_temp_dir().'/fs-glide-'.uniqid();
        config(['fs.glide.cache_path' => $this->glideCache]);
        CountingFile::$glideCalls = 0;
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->glideCache);

        parent::tearDown();
    }

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
        $span = 14 * 86400;
        // Bucketed: valid for between 14 and 28 days, aligned to the 14-day epoch bucket.
        $this->assertGreaterThanOrEqual(now()->timestamp + $span, (int) $params['expires']);
        $this->assertLessThanOrEqual(now()->timestamp + 2 * $span, (int) $params['expires']);
        $this->assertSame(0, (int) $params['expires'] % $span);
    }

    /**
     * A row whose blob is gone must 404, not 500.
     *
     * Glide throws FileNotFoundException when the source is missing, and letting it bubble
     * turned every thumbnail of a deleted blob into a 500 plus a logged exception. A
     * listing of many such rows produced a burst of them. Sibling of the 403 case above.
     */
    public function test_missing_blob_returns_not_found_rather_than_a_server_error(): void
    {
        Storage::fake('local');

        // The row exists and is authorised; only the bytes are gone.
        $file = File::create([
            'name'   => 'gone.jpg',
            'mime'   => 'image/jpeg',
            'disk'   => 'local',
            'path'   => 'test/gone.jpg',
            'width'  => 100,
            'height' => 100,
        ]);

        $signedUrl = URL::temporarySignedRoute('__fs.image', now()->addMinutes(5), [
            'path' => $file->path,
        ]);

        $this->get($signedUrl)->assertNotFound();
    }

    /**
     * A blob the Glide driver cannot decode must 415, not 500.
     *
     * GD cannot read HEIC/HEIF (among others); the row is an authorised image whose bytes
     * exist, so the failure is Intervention's DecoderException. It used to bubble to a 500
     * per thumbnail.
     */
    public function test_undecodable_blob_returns_unsupported_media_type_rather_than_a_server_error(): void
    {
        Storage::fake('local');

        // A minimal HEIC-looking header followed by junk: not decodable by GD.
        Storage::disk('local')->put('test/photo.HEIC', "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".str_repeat("\x01\x02", 64));

        $file = File::create([
            'name'   => 'photo.HEIC',
            'mime'   => 'image/heic',
            'disk'   => 'local',
            'path'   => 'test/photo.HEIC',
        ]);

        $response = $this->get($file->getImageUrl(['w' => 200, 'h' => 200, 'fit' => 'crop'], 7));

        $response->assertStatus(415);
        // The error must not carry the 200's browser-cache lifetime or validators.
        $this->assertStringNotContainsString('max-age', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('ETag'));
    }

    public function test_glide_driver_comes_from_config(): void
    {
        $file = $this->makeImageFile();

        $this->assertInstanceOf(\Intervention\Image\Drivers\Gd\Driver::class, $file->getGlideServer()->getApi()->getImageManager()->driver());

        config(['fs.glide.driver' => 'imagick']);

        $this->assertInstanceOf(\Intervention\Image\Drivers\Imagick\Driver::class, $file->getGlideServer()->getApi()->getImageManager()->driver());
    }

    protected function makeImageFile(array $attributes = []): File
    {
        Storage::fake('local');

        $stored = UploadedFile::fake()->image('photo.jpg', 100, 100)->storeAs('test', 'photo.jpg', 'local');

        return File::create([
            'name'   => 'photo.jpg',
            'mime'   => 'image/jpeg',
            'disk'   => 'local',
            'path'   => $stored,
            'width'  => 100,
            'height' => 100,
            ...$attributes,
        ]);
    }

    public function test_image_url_is_stable_within_a_bucket_and_changes_in_the_next(): void
    {
        $file = $this->makeImageFile();
        $config = ['w' => 200, 'h' => 200, 'fit' => 'crop'];

        $this->travelTo(now()->startOfDay()->addDays(7)->addHour());
        $first = $file->getImageUrl($config, 7);
        parse_str(parse_url($first, PHP_URL_QUERY), $params);
        $expires = (int) $params['expires'];

        // The bucket is (E - 2 x span, E - span]: the URL is identical anywhere inside it.
        $this->travelTo(now()->setTimestamp($expires - 2 * 7 * 86400)->addSecond());
        $this->assertSame($first, $file->getImageUrl($config, 7));
        $this->travelTo(now()->setTimestamp($expires - 7 * 86400));
        $this->assertSame($first, $file->getImageUrl($config, 7));

        // One second past the boundary, the next bucket gives a different URL.
        $this->travelTo(now()->setTimestamp($expires - 7 * 86400 + 1));
        $next = $file->getImageUrl($config, 7);
        $this->assertNotSame($first, $next);
        parse_str(parse_url($next, PHP_URL_QUERY), $nextParams);
        $this->assertSame($expires + 7 * 86400, (int) $nextParams['expires']);
    }

    public function test_image_url_stays_valid_for_at_least_the_requested_days(): void
    {
        $file = $this->makeImageFile();

        $this->travelTo(now()->setTimestamp(1_700_000_001));
        parse_str(parse_url($file->getImageUrl([], 7), PHP_URL_QUERY), $params);

        $this->assertGreaterThanOrEqual(now()->timestamp + 7 * 86400, (int) $params['expires']);
    }

    public function test_zero_valid_still_yields_a_non_expiring_url(): void
    {
        $file = $this->makeImageFile();

        $url = $file->getImageUrl([], 0);
        $this->assertStringNotContainsString('expires=', $url);
        $this->get($url)->assertOk();
    }

    public function test_response_is_browser_cacheable_but_private(): void
    {
        $file = $this->makeImageFile();

        $url = $file->getImageUrl(['w' => 50], 7);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $response = $this->get($url)->assertOk();

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
        $this->assertStringNotContainsString('no-cache', $cacheControl);
        $this->assertMatchesRegularExpression('/max-age=(\d+)/', $cacheControl);
        preg_match('/max-age=(\d+)/', $cacheControl, $m);
        $this->assertEqualsWithDelta((int) $params['expires'] - now()->timestamp, (int) $m[1], 5);

        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $response->headers->get('ETag'));
        $this->assertNotNull($response->headers->get('Last-Modified'));
    }

    public function test_max_age_falls_back_when_url_has_no_expiry(): void
    {
        $file = $this->makeImageFile();

        $response = $this->get($file->getImageUrl([], 0))->assertOk();

        $this->assertStringContainsString('max-age=604800', $response->headers->get('Cache-Control'));
    }

    public function test_response_still_works_when_updated_at_is_null(): void
    {
        $file = $this->makeImageFile();
        File::query()->whereKey($file->getKey())->toBase()->update(['updated_at' => null]);

        $response = $this->get($file->getImageUrl([], 7))->assertOk();

        $this->assertNotNull($response->headers->get('ETag'));
        $this->assertNull($response->headers->get('Last-Modified'));
    }

    public function test_etag_is_stable_across_url_buckets_and_varies_with_params(): void
    {
        $file = $this->makeImageFile();

        $a = $this->get($file->getImageUrl(['w' => 50, 'h' => 50], 7))->headers->get('ETag');
        $this->travelTo(now()->addDays(8));
        $b = $this->get($file->getImageUrl(['h' => 50, 'w' => 50], 7))->headers->get('ETag');
        $c = $this->get($file->getImageUrl(['w' => 60, 'h' => 50], 7))->headers->get('ETag');

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function test_matching_if_none_match_returns_304_without_touching_glide(): void
    {
        $file = $this->makeImageFile();
        $url = $file->getImageUrl(['w' => 50], 7);

        $etag = $this->get($url)->assertOk()->headers->get('ETag');

        // Delete the blob and count Glide use: a 304 must involve neither.
        config(['fs.models.file' => CountingFile::class]);
        Storage::disk('local')->delete($file->path);
        $this->assertSame(0, CountingFile::$glideCalls);

        $response = $this->get($url, ['If-None-Match' => $etag]);

        $this->assertSame(0, CountingFile::$glideCalls);

        $response->assertStatus(304);
        $this->assertSame('', $response->getContent());
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame($etag, $response->headers->get('ETag'));
    }

    public function test_if_modified_since_returns_304(): void
    {
        $file = $this->makeImageFile();
        $url = $file->getImageUrl([], 7);

        $lastModified = $this->get($url)->assertOk()->headers->get('Last-Modified');

        $this->get($url, ['If-Modified-Since' => $lastModified])->assertStatus(304);
    }

    public function test_non_matching_if_none_match_returns_200(): void
    {
        $file = $this->makeImageFile();

        config(['fs.models.file' => CountingFile::class]);

        $this->get($file->getImageUrl([], 7), ['If-None-Match' => '"nope"'])
            ->assertOk()
            ->assertHeader('ETag');

        $this->assertSame(1, CountingFile::$glideCalls);
    }

    public function test_auth_failure_is_403_even_with_a_matching_etag(): void
    {
        $file = $this->makeImageFile();
        $url = $file->getImageUrl([], 7);
        $etag = $this->get($url)->assertOk()->headers->get('ETag');

        config(['fs.models.file' => DenyingFile::class]);

        $this->get($url, ['If-None-Match' => $etag])->assertForbidden();
    }

    public function test_missing_blob_is_still_404_when_etag_does_not_match(): void
    {
        $file = $this->makeImageFile();
        $url = $file->getImageUrl([], 7);
        Storage::disk('local')->delete($file->path);

        $this->get($url, ['If-None-Match' => '"stale"'])->assertNotFound();
    }
}

class DenyingFile extends File
{
    protected $table = 'files';

    public function auth(): bool
    {
        return false;
    }
}

class CountingFile extends File
{
    protected $table = 'files';

    public static int $glideCalls = 0;

    public function getGlideServer()
    {
        static::$glideCalls++;

        return parent::getGlideServer();
    }
}
