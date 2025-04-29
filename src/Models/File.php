<?php

namespace Jiannius\Filesystem\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Model;
use Jiannius\Filesystem\Services\Optimizer;
use Jiannius\Filesystem\Services\Util;

class File extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $casts = [
        'kb' => 'float',
        'width' => 'integer',
        'height' => 'integer',
        'data' => 'array',
        'is_resized' => 'boolean',
        'is_converted_to_webp' => 'boolean',
    ];

    protected $appends = [
        'size',
        'is_image',
        'is_video',
        'is_audio',
        'is_youtube',
        'is_file',
        'endpoint',
        'endpoint_o',
    ];

    public const MIME = [
        "txt" => "text/plain",
        "html" => "text/html",
        "htm" => "text/html",
        "css" => "text/css",
        "js" => "text/javascript",
        "mjs" => "text/javascript",
        "csv" => "text/csv",
        "xml" => "application/xml", // Can also be text/xml, application/xml is often preferred
        "jpg" => "image/jpeg",
        "jpeg" => "image/jpeg",
        "png" => "image/png",
        "gif" => "image/gif",
        "svg" => "image/svg+xml",
        "webp" => "image/webp",
        "bmp" => "image/bmp",
        "tif" => "image/tiff",
        "tiff" => "image/tiff",
        "ico" => "image/x-icon",
        "mp3" => "audio/mpeg",
        "ogg" => "audio/ogg",
        "oga" => "audio/ogg",
        "wav" => "audio/wav",
        "aac" => "audio/aac",
        "weba" => "audio/webm",
        "mp4" => "video/mp4",
        "mpeg" => "video/mpeg",
        "mpg" => "video/mpeg",
        "ogv" => "video/ogg",
        "webm" => "video/webm",
        "mov" => "video/quicktime",
        "avi" => "video/x-msvideo",
        "pdf" => "application/pdf",
        "json" => "application/json",
        "jsonld" => "application/ld+json",
        "zip" => "application/zip",
        "gz" => "application/gzip",
        "rar" => "application/vnd.rar", // Note: Often application/x-rar is used but not official
        "tar" => "application/x-tar",
        "7z" => "application/x-7z-compressed",
        "bin" => "application/octet-stream", // Generic binary
        "doc" => "application/msword",
        "docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        "xls" => "application/vnd.ms-excel",
        "xlsx" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        "ppt" => "application/vnd.ms-powerpoint",
        "pptx" => "application/vnd.openxmlformats-officedocument.presentationml.presentation",
        "odt" => "application/vnd.oasis.opendocument.text",
        "ods" => "application/vnd.oasis.opendocument.spreadsheet",
        "odp" => "application/vnd.oasis.opendocument.presentation",
        "woff" => "font/woff",
        "woff2" => "font/woff2",
        "ttf" => "font/ttf",
        "otf" => "font/otf"
    ];

    public const OPTIMIZATION_SUFFIX = '--o'; // suffix to be append to optimized image, eg: XXXXXX--o.jpg

    protected static function booted() : void
    {
        static::saving(function ($file) {
            if ($file->isDirty('data')) $file->setVisibility();
        });

        static::deleting(function ($file) {
            $file->preventProductionDelete();
            $file->deleteFromDisk();
        });
    }

    protected function isImage() : Attribute
    {
        return Attribute::make(
            get: fn() => str()->startsWith($this->mime, 'image/'),
        );
    }

    protected function isVideo() : Attribute
    {
        return Attribute::make(
            get: fn() => str()->startsWith($this->mime, 'video/'),
        );
    }

    protected function isAudio() : Attribute
    {
        return Attribute::make(
            get: fn() => str()->startsWith($this->mime, 'audio/'),
        );
    }

    protected function isYoutube() : Attribute
    {
        return Attribute::make(
            get: fn() => $this->mime === 'youtube',
        );
    }

    protected function isFile() : Attribute
    {
        return Attribute::make(
            get: fn() => !$this->is_image && !$this->is_video && !$this->is_audio && !$this->is_youtube,
        );
    }

    // return file size in 5.25KB
    protected function size() : Attribute
    {
        return Attribute::make(
            get: fn() => Util::filesize((float) $this->kb),
        );
    }

    protected function filename() : Attribute
    {
        return Attribute::make(
            get: fn() => $this->path ? last(explode('/', $this->path)) : null,
        );
    }

    protected function storagePath() : Attribute
    {
        return Attribute::make(
            get: fn() => $this->disk === 'local' ? storage_path("app/{$this->path}") : null,
        );
    }

    protected function endpoint() : Attribute
    {
        return Attribute::make(
            get: fn () => $this->getEndpoint(),
        );
    }

    protected function endpointO() : Attribute
    {
        return Attribute::make(
            get: fn () => $this->getEndpoint(optimized: true),
        );
    }

    protected function type() : Attribute
    {
        return Attribute::make(
            get: function() {
                $mime = str($this->mime);

                if ($mime->is('image/*')) return (string) $mime->replace('image/', '');

                return match (true) {
                    $mime->is('youtube') => 'youtube',
                    $mime->is('*ld+json') => 'jsonld',
                    $mime->is('*svg+xml') => 'svg',
                    $mime->is('*plain') => 'text',

                    $mime->is('*msword')
                    || $mime->is('*vnd.openxmlformats-officedocument.wordprocessingml.document') => 'word',

                    $mime->is('*vnd.ms-powerpoint')
                    || $mime->is('*vnd.openxmlformats-officedocument.presentationml.presentation') => 'ppt',

                    $mime->is('*vnd.ms-excel')
                    || $mime->is('*vnd.openxmlformats-officedocument.spreadsheetml.sheet') => 'excel',

                    $mime->is('*/pdf') => 'pdf',
                    $mime->is('video/*') => 'video',
                    $mime->is('audio/*') => 'audio',
                    default => 'file',
                };
            },
        );
    }

    public function scopeSearch($query, $search) : void
    {
        $query->where('name', 'like', "%$search%");
    }

    public function scopeMime($query, $mime) : void
    {
        if (!$mime) return;

        $mime = explode(',', $mime);

        $query->where(function($q) use ($mime) {
            foreach ($mime as $val) {
                if ($val === 'file') {
                    $q->orWhere(fn($q) => $q
                        ->where('mime', 'not like', 'image/%')
                        ->where('mime', 'not like', 'video/%')
                        ->where('mime', 'not like', 'audio/%')
                        ->where('mime', '<>', 'youtube')
                    );
                }
                else {
                    $q->orWhere('mime', 'like', str($val)->replace('*', '%')->toString());
                }
            }
        });
    }

    public function auth() : bool
    {
        return true;
    }

    public function isVisibilityEditable() : bool
    {
        return true;
    }

    public function getEndpoint($noauth = false, $optimized = false)
    {
        if ($this->is_youtube) return Util::getYoutubeEmbedUrl($this->url);

        $e404 = 'https://placehold.co/300?text=404&font=lato';
        $e403 = 'https://placehold.co/300?text=Error403&font=lato';
        $path = $optimized ? $this->getOptimizedPath() : $this->path;

        $endpoint = match (true) {
            $this->isDisk('local') => asset('storage/'.$this->path),
            $this->isDisk('do', 's3') && $this->visibility === 'private' => $this->getDisk()->temporaryUrl($path, now()->addHour()),
            $this->isDisk('do', 's3') && $this->visibility !== 'private' => $this->getDisk()->url($path),
            default => $this->url,
        };

        if (!$endpoint) return $e404;
        if (!$noauth && !$this->auth()) return $e403;

        return $endpoint;
    }

    public function getBase64($noauth = false, $optimized = false)
    {
        $endpoint = $this->getEndpoint($noauth, $optimized);

        if (!$endpoint) return null;

        $ext = pathinfo($endpoint, PATHINFO_EXTENSION);
        $content = file_get_contents($endpoint);

        return 'data:image/'.$ext.';base64,'.base64_encode($content);
    }

    public function getOptimizedPath()
    {
        if (!$this->is_resized && !$this->is_converted_to_webp) return $this->path;

        $split = collect(explode('.', $this->path));
        $extension = $split->pop();

        if ($this->is_converted_to_webp) $extension = 'webp';

        return $split->push(self::OPTIMIZATION_SUFFIX)->join('').'.'.$extension;
    }

    public function getDisk()
    {
        return Storage::disk($this->disk);
    }

    public function isDisk(...$name)
    {
        return in_array($this->disk, (array) $name);
    }

    public function getStoreSettings()
    {
        return [
            'path' => null,
            'visibility' => 'public',
            'optimization' => [], // default optimization settings refer to Services\Optimizer
        ];
    }

    public function store($content, $settings = [])
    {
        if (
            $this->storeYoutube($content)
            ?? $this->storeImageUrl($content)
            ?? $this->storeUploaded($content, $settings)
        ) {
            $optimizationSettings = data_get($settings, 'optimization');

            if ($optimizationSettings === false) return $this;

            return $this->optimize($optimizationSettings);
        }
    }

    public function storeYoutube($content)
    {
        if (!is_string($content)) return;

        $vid = Util::getYoutubeVideoId($content);

        if (!$vid) return;

        $info = Util::getYoutubeVideoInfo($content);

        return $this->fill([
            'name' => data_get($info, 'title') ?? $vid,
            'mime' => 'youtube',
            'url' => $content,
            'data' => [
                'vid' => $vid,
                'thumbnail' => data_get($info, 'thumbnail_url'),
            ],
        ])->save();
    }

    public function storeImageUrl($content)
    {
        if (!is_string($content)) return;

        $img = getimagesize($content);

        if (!$img) return;

        return $this->fill([
            'name' => $content,
            'mime' => data_get($img, 'mime'),
            'url' => $content,
            'width' => data_get($img, 0),
            'height' => data_get($img, 1),
        ])->save();
    }

    public function storeUploaded($content, $settings)
    {
        if (!$content->path()) return;

        $settings = [...$this->getStoreSettings(), ...$settings];
        $extension = Util::getUploadedFileExtension($content);
        $mime = data_get(self::MIME, $extension) ?? $content->getMimeType();
        $size = round($content->getSize()/1024, 5);
        $isImage = str($mime)->is('image/*');
        $dimension = $isImage ? getimagesize($content->path()) : null;
        $width = data_get($dimension, 0);
        $height = data_get($dimension, 1);
        $saveAs = str()->random(30);

        $this->fill([
            'name' => $content->getClientOriginalName(),
            'extension' => $extension,
            'kb' => $size,
            'mime' => $mime,
            'disk' => env('FILESYSTEM_DISK'),
            'width' => $width,
            'height' => $height,
            'env' => app()->environment(),
        ]);

        $disk = $this->getDisk();

        $folder = collect([
            data_get($disk->getConfig(), 'folder'),
            data_get($settings, 'path'),
        ])->filter()->join('/');

        $visibility = data_get($settings, 'visibility');
        $path = $disk->putFileAs($folder, $content->path(), $saveAs.'.'.$extension, $visibility);
        $url = $this->disk !== 'local' ? $disk->url($path) : null;

        return $this->fill([
            'url' => $url,
            'path' => $path,
        ])->save();
    }

    public function preventProductionDelete()
    {
        if (!$this->isDisk('do', 's3')) return;
        if (!$this->path) return;

        throw_if(
            $this->env === 'production' && !app()->environment('production'),
            \Exception::class,
            'Do not delete production file in '.app()->environment().' environment!',
        );
    }

    public function deleteFromDisk()
    {
        if (!$this->path) return;

        if ($this->is_resized || $this->is_converted_to_webp) {
            $this->getDisk()->delete($this->getOptimizedPath());
        }

        $this->getDisk()->delete($this->path);
    }

    public function setVisibility()
    {
        $visibility = $this->visibility ?? 'public';

        $this->getDisk()->setVisibility($this->path, $visibility);

        if ($this->is_optimized) {
            $this->getDisk()->setVisibility($this->getOptimizedPath(), $visibility);
        }
    }

    public function optimize($settings = [])
    {
        if (!$this->is_image) return $this;

        if ($optimized = rescue(fn () => (new Optimizer($this->endpoint))->settings($settings)->optimize(), null, false)) {
            $isResized = str($optimized)->is('*--resized*');
            $isConvertedToWebp = str($optimized)->is('*--webp*');
            $extension = $isConvertedToWebp ? 'webp' : $this->extension;
            $saveAs = head(explode('.', $this->filename)).self::OPTIMIZATION_SUFFIX.'.'.$extension;
            $path = (string) str($this->path)->replace($this->filename, '')->replaceLast('/', '');

            $this->getDisk()->putFileAs($path, $optimized, $saveAs, $this->visibility);
            unlink($optimized);

            $this->fill([
                'is_resized' => $isResized,
                'is_converted_to_webp' => $isConvertedToWebp,
            ])->save();
        }

        return $this;
    }
}
