<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Jiannius\Filesystem\Controllers\UploadController;
use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Support\UploadGuard;
use Jiannius\Filesystem\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class UploadGuardTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->withoutMiddleware();
    }

    private function upload(UploadedFile $file)
    {
        return $this->postJson(route('__fs.upload'), ['file' => $file]);
    }

    private function content(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_valid_png_is_accepted(): void
    {
        $this->upload($this->content('pixel.png', base64_decode(self::PNG)))->assertOk();

        $this->assertSame(1, File::query()->count());
    }

    public function test_generated_image_is_accepted(): void
    {
        $this->upload(UploadedFile::fake()->image('photo.JPG', 40, 30))->assertOk();
    }

    public function test_valid_pdf_is_accepted(): void
    {
        $this->upload($this->content('doc.pdf', "%PDF-1.4\n1 0 obj\n<< >>\nendobj\n%%EOF\n"))->assertOk();
    }

    public function test_valid_csv_is_accepted(): void
    {
        $this->upload($this->content('rows.csv', "name,qty\nwidget,2\n<5 units,3\n"))->assertOk();
    }

    public function test_zip_based_office_file_is_accepted(): void
    {
        $this->upload($this->content('sheet.xlsx', "PK\x03\x04\x14\x00\x00\x00\x08\x00binary"))->assertOk();
    }

    public function test_ole_office_file_is_accepted(): void
    {
        $this->upload($this->content('old.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 64)))->assertOk();
    }

    #[DataProvider('disallowedExtensions')]
    public function test_disallowed_extension_is_refused(string $name): void
    {
        $this->upload($this->content($name, 'hello'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, File::query()->count());
    }

    public static function disallowedExtensions(): array
    {
        return [
            'html' => ['page.html'],
            'svg' => ['image.svg'],
            'php' => ['script.php'],
            'js' => ['app.js'],
            'xml' => ['data.xml'],
            'double extension' => ['photo.png.html'],
            'no extension' => ['README'],
        ];
    }

    public function test_extension_check_is_case_insensitive(): void
    {
        $this->upload($this->content('NOTES.TXT', 'plain notes'))->assertOk();
        $this->upload($this->content('PAGE.HTML', 'plain notes'))->assertStatus(422);
    }

    public function test_jpg_containing_html_is_refused(): void
    {
        $this->upload($this->content('photo.jpg', "<!DOCTYPE html>\n<html><body>hi</body></html>"))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, File::query()->count());
    }

    public function test_png_that_is_not_an_image_is_refused(): void
    {
        $this->upload($this->content('photo.png', 'just some text'))->assertStatus(422);
    }

    public function test_pdf_without_pdf_header_is_refused(): void
    {
        $this->upload($this->content('doc.pdf', 'not a pdf'))->assertStatus(422);
    }

    public function test_txt_containing_php_is_refused(): void
    {
        $this->upload($this->content('notes.txt', "some notes\n<?php echo 1; ?>"))->assertStatus(422);
    }

    public function test_csv_with_leading_markup_after_bom_and_whitespace_is_refused(): void
    {
        $this->upload($this->content('rows.csv', "\xEF\xBB\xBF \n\t<svg onload=x>"))->assertStatus(422);
    }

    public function test_text_that_is_xml_is_refused(): void
    {
        $this->upload($this->content('data.txt', "\n<?xml version=\"1.0\"?><a/>"))->assertStatus(422);
    }

    public function test_script_tag_after_4kb_of_padding_in_a_txt_is_refused(): void
    {
        $this->upload($this->content('notes.txt', str_repeat('a', 5000).'<script>x</script>'))->assertStatus(422);
        $this->upload($this->content('rows.csv', str_repeat("a,b\n", 3000).'<script>x</script>'))->assertStatus(422);
    }

    public function test_leading_img_tag_in_a_txt_is_refused(): void
    {
        $this->upload($this->content('notes.txt', '<img src=x onerror=y>'))->assertStatus(422);
    }

    public function test_embedding_tags_in_a_csv_are_refused(): void
    {
        $this->upload($this->content('a.csv', "a,b\n<iframe src=x>"))->assertStatus(422);
        $this->upload($this->content('b.csv', "a,b\n<object data=x>"))->assertStatus(422);
        $this->upload($this->content('c.csv', "a,b\n<embed src=x>"))->assertStatus(422);
    }

    public function test_embedded_nul_bytes_do_not_hide_markup_in_a_txt(): void
    {
        $this->upload($this->content('notes.txt', "hello <scr\0ipt>x</scr\0ipt>"))->assertStatus(422);
    }

    public function test_pdf_header_is_accepted_within_the_first_1024_bytes(): void
    {
        $this->upload($this->content('doc.pdf', str_repeat(' ', 500)."%PDF-1.7\n%%EOF\n"))->assertOk();
    }

    public function test_pdf_header_after_1024_bytes_is_refused(): void
    {
        $this->upload($this->content('doc.pdf', str_repeat(' ', 1100)."%PDF-1.7\n%%EOF\n"))->assertStatus(422);
    }

    public function test_script_tag_in_a_pdf_is_refused(): void
    {
        $this->upload($this->content('doc.pdf', "%PDF-1.4\n<script>alert(1)</script>"))->assertStatus(422);
    }

    public function test_markup_is_refused_even_when_the_extension_is_allowed_by_config(): void
    {
        config(['fs.upload.extensions' => ['html', 'svg', 'php']]);

        $this->upload($this->content('page.html', '<html><body>hi</body></html>'))->assertStatus(422);
        $this->upload($this->content('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'))->assertStatus(422);
        $this->upload($this->content('x.php', '<?php echo 1;'))->assertStatus(422);
    }

    public function test_extension_list_is_configurable(): void
    {
        config(['fs.upload.extensions' => ['txt']]);

        $this->upload($this->content('rows.csv', "a,b\n"))->assertStatus(422);
        $this->upload($this->content('notes.txt', 'hi'))->assertOk();
    }

    public function test_oversize_file_is_refused(): void
    {
        config(['fs.upload.max_kb' => 1]);

        $this->upload($this->content('notes.txt', str_repeat('a', 2048)))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, File::query()->count());
    }

    public function test_missing_file_is_refused(): void
    {
        $this->postJson(route('__fs.upload'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_store_upload_itself_is_not_restricted(): void
    {
        $file = File::storeUpload($this->content('page.html', '<html></html>'));

        $this->assertSame('html', $file->extension);
    }

    public function test_guard_can_be_replaced_by_a_subclassed_controller(): void
    {
        $controller = new class extends UploadController {
            public function guard(): UploadGuard
            {
                return $this->uploadGuard();
            }

            protected function uploadGuard(): UploadGuard
            {
                return new UploadGuard(['txt']);
            }
        };

        $this->assertNull($controller->guard()->check($this->content('a.txt', 'hi')));
        $this->assertNotNull($controller->guard()->check($this->content('a.png', 'hi')));
    }
}
