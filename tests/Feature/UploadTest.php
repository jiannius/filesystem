<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class UploadTest extends TestCase
{
    public function test_upload_writes_file_to_disk_and_creates_db_row(): void
    {
        Storage::fake('local');
        $this->withoutMiddleware();

        $upload = UploadedFile::fake()->image('photo.jpg', 800, 600);

        $response = $this->postJson(route('__fs.upload'), [
            'file' => $upload,
        ]);

        $response->assertOk();
        $this->assertSame(1, File::query()->count());

        $file = File::query()->firstOrFail();
        $this->assertSame('photo.jpg', $file->name);
        $this->assertStringStartsWith('image/', $file->mime);
        Storage::disk('local')->assertExists($file->path);
    }

    public function test_uppercase_heic_extension_gets_the_heic_mime_but_keeps_its_extension(): void
    {
        Storage::fake('local');

        $upload = UploadedFile::fake()->createWithContent('IMG_0001.HEIC', "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic");

        $file = File::storeUpload($upload, '', 'private');

        $this->assertSame('image/heic', $file->mime);
        $this->assertSame('HEIC', $file->extension);
        $this->assertSame('image/heif', data_get(File::MIME, 'heif'));
    }

    public function test_upload_route_uses_configured_file_model_class(): void
    {
        Storage::fake('local');
        $this->withoutMiddleware();

        config(['fs.models.file' => TestFileSubclass::class]);
        TestFileSubclass::$wasCalled = false;

        $this->postJson(route('__fs.upload'), [
            'file' => UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\n%%EOF\n"),
        ])->assertOk();

        $this->assertTrue(TestFileSubclass::$wasCalled, 'Configured file class was not used by controller');
    }
}

// Defined at bottom of file so it's bootable from the autoloader during the test run.
class TestFileSubclass extends File
{
    protected $table = 'files';

    public static bool $wasCalled = false;

    public static function storeUpload($upload, $folder = '', $visibility = 'public')
    {
        static::$wasCalled = true;
        return parent::storeUpload($upload, $folder, $visibility);
    }
}
