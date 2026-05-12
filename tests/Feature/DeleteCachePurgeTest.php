<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class DeleteCachePurgeTest extends TestCase
{
    public function test_deleting_file_removes_disk_entry(): void
    {
        Storage::fake('local');

        $stored = UploadedFile::fake()->image('img.jpg', 50, 50)
            ->storeAs('uploads', 'img.jpg', 'local');

        $file = File::create([
            'name' => 'img.jpg',
            'mime' => 'image/jpeg',
            'disk' => 'local',
            'path' => $stored,
        ]);

        Storage::disk('local')->assertExists($stored);

        $file->delete();

        Storage::disk('local')->assertMissing($stored);
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_deleting_file_without_path_is_a_noop_on_disk(): void
    {
        $file = File::create([
            'name' => 'video',
            'mime' => 'youtube',
        ]);

        $file->delete();

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }
}
