<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class SoftDeleteTest extends TestCase
{
    protected string $glideCache;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['fs.models.file' => TrashableFile::class]);

        $this->glideCache = sys_get_temp_dir().'/fs-glide-'.uniqid();
        config(['fs.glide.cache_path' => $this->glideCache]);
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->glideCache);

        parent::tearDown();
    }

    protected function makeFile(string $class = TrashableFile::class, array $attributes = []): File
    {
        $stored = UploadedFile::fake()->image('img.jpg', 50, 50)->storeAs('uploads', 'img.jpg', 'local');

        return $class::create([
            'name' => 'img.jpg',
            'mime' => 'image/jpeg',
            'disk' => 'local',
            'path' => $stored,
            ...$attributes,
        ]);
    }

    public function test_soft_delete_keeps_the_object_and_glide_cache(): void
    {
        $file = $this->makeFile();

        // Warm the Glide cache.
        $this->get($file->getImageUrl(['w' => 20]))->assertOk();
        $cached = fn () => collect((new \Illuminate\Filesystem\Filesystem)->allFiles($this->glideCache))->count();
        $this->assertGreaterThan(0, $cached());
        $before = $cached();

        $file->delete();

        Storage::disk('local')->assertExists($file->path);
        $this->assertSame($before, $cached());
        $this->assertSoftDeleted('files', ['id' => $file->id]);
    }

    public function test_force_delete_removes_the_object(): void
    {
        $file = $this->makeFile();

        $file->forceDelete();

        Storage::disk('local')->assertMissing($file->path);
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_force_delete_of_an_already_trashed_file_removes_the_object(): void
    {
        $file = $this->makeFile();
        $file->delete();
        Storage::disk('local')->assertExists($file->path);

        TrashableFile::withTrashed()->find($file->id)->forceDelete();

        Storage::disk('local')->assertMissing($file->path);
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_restore_makes_the_file_reachable_with_the_object_intact(): void
    {
        $file = $this->makeFile();
        $file->delete();

        $this->assertNull(TrashableFile::find($file->id));

        $file->restore();

        $this->assertNotNull(TrashableFile::find($file->id));
        Storage::disk('local')->assertExists($file->path);
        $this->get($file->getImageUrl(['w' => 20]))->assertOk();
    }

    public function test_soft_deleting_a_production_cloud_row_in_non_production_does_not_throw(): void
    {
        $file = TrashableFile::create([
            'name' => 'prod.jpg',
            'mime' => 'image/jpeg',
            'disk' => 's3',
            'path' => 'fake/prod.jpg',
            'env'  => 'production',
        ]);

        $file->delete();

        $this->assertSoftDeleted('files', ['id' => $file->id]);
    }

    public function test_force_deleting_a_production_cloud_row_in_non_production_still_throws(): void
    {
        $file = TrashableFile::create([
            'name' => 'prod.jpg',
            'mime' => 'image/jpeg',
            'disk' => 's3',
            'path' => 'fake/prod.jpg',
            'env'  => 'production',
        ]);

        try {
            $file->forceDelete();
            $this->fail('Expected the production-delete guard to throw.');
        } catch (\Exception $e) {
            $this->assertMatchesRegularExpression('/Do not delete production file/', $e->getMessage());
        }

        $this->assertDatabaseHas('files', ['id' => $file->id]);
    }

    public function test_signed_image_url_for_a_soft_deleted_file_is_not_found(): void
    {
        $file = $this->makeFile();
        $url = URL::temporarySignedRoute('__fs.image', now()->addMinutes(5), ['path' => $file->path]);

        $this->get($url)->assertOk();

        $file->delete();

        $this->get($url)->assertNotFound();
    }

    public function test_package_file_without_soft_deletes_still_removes_the_object(): void
    {
        config(['fs.models.file' => File::class]);
        $file = $this->makeFile(File::class);

        $file->delete();

        Storage::disk('local')->assertMissing($file->path);
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_package_file_without_soft_deletes_still_guards_production_rows(): void
    {
        $file = File::create([
            'name' => 'prod.jpg',
            'mime' => 'image/jpeg',
            'disk' => 's3',
            'path' => 'fake/prod.jpg',
            'env'  => 'production',
        ]);

        $this->expectExceptionMessageMatches('/Do not delete production file/');

        $file->delete();
    }

    public function test_migration_adds_deleted_at_and_is_safe_to_rerun(): void
    {
        $migration = require __DIR__.'/../../database/migrations/filesystem_0002_add_deleted_at_to_files_table.php';

        $this->assertTrue(Schema::hasColumn('files', 'deleted_at'));

        // Re-running with the column present is a no-op.
        $migration->up();
        $this->assertTrue(Schema::hasColumn('files', 'deleted_at'));

        // Down removes it, and is itself safe to repeat.
        $migration->down();
        $this->assertFalse(Schema::hasColumn('files', 'deleted_at'));
        $migration->down();

        // Up adds it back.
        $migration->up();
        $this->assertTrue(Schema::hasColumn('files', 'deleted_at'));
    }
}

class TrashableFile extends File
{
    use SoftDeletes;

    protected $table = 'files';
}
