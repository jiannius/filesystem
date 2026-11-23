<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class UploadPolicyTest extends TestCase
{
    public function test_client_visibility_and_folder_are_ignored(): void
    {
        Storage::fake('local');
        $this->withoutMiddleware();

        $this->postJson(route('__fs.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg'),
            'settings' => ['visibility' => 'public', 'folder' => 'somewhere/else'],
        ])->assertOk();

        $file = File::query()->firstOrFail();
        $this->assertSame('private', $file->visibility);
        $this->assertStringNotContainsString('/', $file->path);
    }

    public function test_configured_visibility_and_folder_are_used(): void
    {
        Storage::fake('local');
        $this->withoutMiddleware();
        config(['fs.upload.visibility' => 'public', 'fs.upload.folder' => 'uploads']);

        $this->postJson(route('__fs.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg'),
            'settings' => ['visibility' => 'private', 'folder' => 'ignored'],
        ])->assertOk();

        $file = File::query()->firstOrFail();
        $this->assertSame('public', $file->visibility);
        $this->assertStringStartsWith('uploads/', $file->path);
    }

    public function test_default_config_values(): void
    {
        $this->assertSame('private', config('fs.upload.visibility'));
        $this->assertSame('', config('fs.upload.folder'));
        $this->assertNull(config('fs.upload.authorize'));
    }

    public function test_programmatic_store_upload_keeps_its_own_defaults(): void
    {
        Storage::fake('local');

        $file = File::storeUpload(UploadedFile::fake()->image('photo.jpg'));

        $this->assertSame('public', $file->visibility);
    }

    public function test_authorize_returning_false_aborts_with_403(): void
    {
        Storage::fake('local');
        $this->withoutMiddleware();
        config(['fs.upload.authorize' => fn (Request $request) => false]);

        $this->postJson(route('__fs.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ])->assertForbidden();

        $this->assertSame(0, File::query()->count());
    }

    public function test_authorize_returning_true_allows_the_upload(): void
    {
        Storage::fake('local');
        $this->withoutMiddleware();
        config(['fs.upload.authorize' => fn (Request $request) => true]);

        $this->postJson(route('__fs.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ])->assertOk();
    }

    public function test_authorize_accepts_an_invokable_class_name(): void
    {
        Storage::fake('local');
        $this->withoutMiddleware();
        config(['fs.upload.authorize' => DenyUploads::class]);

        $this->postJson(route('__fs.upload'), [
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ])->assertForbidden();
    }

    public function test_authorize_also_gates_url_uploads(): void
    {
        $this->withoutMiddleware();
        config(['fs.allow_url_fetch' => true, 'fs.upload.authorize' => fn () => false]);

        $this->postJson(route('__fs.upload'), ['url' => ['https://example.com/a.png']])
            ->assertForbidden();

        $this->assertSame(0, File::query()->count());
    }

    public function test_upload_route_is_throttled_and_authenticated_by_default(): void
    {
        $middleware = Route::getRoutes()->getByName('__fs.upload')->gatherMiddleware();

        $this->assertContains('auth', $middleware);
        $this->assertContains('throttle:30,1', $middleware);
    }
}

class DenyUploads
{
    public function __invoke(Request $request): bool
    {
        return false;
    }
}
