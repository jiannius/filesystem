<?php

namespace Jiannius\Filesystem\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    /**
     * The booted method for model
     */
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

    /**
     * Get the user for file
     */
    public function user() : BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    /**
     * The is_image attribute for file
     */
    protected function isImage() : Attribute
    {
        return Attribute::make(
            get: fn() => str()->startsWith($this->mime, 'image/'),
        );
    }

    /**
     * The is_video attribute for file
     */
    protected function isVideo() : Attribute
    {
        return Attribute::make(
            get: fn() => str()->startsWith($this->mime, 'video/'),
        );
    }

    /**
     * The is_audio attribute for file
     */
    protected function isAudio() : Attribute
    {
        return Attribute::make(
            get: fn() => str()->startsWith($this->mime, 'audio/'),
        );
    }

    /**
     * The is_youtube attribute for file
     */
    protected function isYoutube() : Attribute
    {
        return Attribute::make(
            get: fn() => $this->mime === 'youtube',
        );
    }

    /**
     * The is_file attribute for file
     */
    protected function isFile() : Attribute
    {
        return Attribute::make(
            get: fn() => !$this->is_image && !$this->is_video && !$this->is_audio && !$this->is_youtube,
        );
    }

    /**
     * The size attribute for file
     */
    protected function size() : Attribute
    {
        return Attribute::make(
            get: fn() => Util::filesize((float) $this->kb),
        );
    }

    /**
     * The filename attribute for file
     */
    protected function filename() : Attribute
    {
        return Attribute::make(
            get: fn() => $this->path ? last(explode('/', $this->path)) : null,
        );
    }

    /**
     * The storage_path attribute for file
     */
    protected function storagePath() : Attribute
    {
        return Attribute::make(
            get: fn() => $this->disk === 'local' ? storage_path("app/{$this->path}") : null,
        );
    }

    /**
     * The endpoint attribute for file
     */
    protected function endpoint() : Attribute
    {
        return Attribute::make(
            get: fn () => $this->getEndpoint(),
        );
    }

    /**
     * The endpoint_o attribute for file
     */
    protected function endpointO() : Attribute
    {
        return Attribute::make(
            get: fn () => $this->getEndpoint(optimized: true),
        );
    }

    /**
     * The type attribute for file
     */
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

    /**
     * The icon attribute for file
     */
    protected function icon() : Attribute
    {
        return Attribute::make(
            get: function () {
                $icon = match ($this->type) {
                    'image' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-image-icon lucide-image"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>',
                    'video' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-video-icon lucide-video"><path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg>',
                    'audio' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-music-icon lucide-music"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
                    'word' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M17 19H20V4.99997H17V2.99997H21C21.5523 2.99997 22 3.44769 22 3.99997V20C22 20.5523 21.5523 21 21 21H17V19ZM2.85858 2.87732L15.4293 1.0815C15.7027 1.04245 15.9559 1.2324 15.995 1.50577C15.9983 1.52919 16 1.55282 16 1.57648V22.4235C16 22.6996 15.7761 22.9235 15.5 22.9235C15.4763 22.9235 15.4527 22.9218 15.4293 22.9184L2.85858 21.1226C2.36593 21.0522 2 20.6303 2 20.1327V3.86727C2 3.36962 2.36593 2.9477 2.85858 2.87732ZM4 4.73457V19.2654L14 20.694V3.30599L4 4.73457ZM11 7.99997H13V16H11L9 14L7 16H5V7.99997H7L7.01083 13L9 11L11 12.989V7.99997Z"></path></svg>',
                    'excel' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M2.85858 2.87732L15.4293 1.0815C15.7027 1.04245 15.9559 1.2324 15.995 1.50577C15.9983 1.52919 16 1.55282 16 1.57648V22.4235C16 22.6996 15.7761 22.9235 15.5 22.9235C15.4763 22.9235 15.4527 22.9218 15.4293 22.9184L2.85858 21.1226C2.36593 21.0522 2 20.6303 2 20.1327V3.86727C2 3.36962 2.36593 2.9477 2.85858 2.87732ZM4 4.73457V19.2654L14 20.694V3.30599L4 4.73457ZM17 19H20V4.99997H17V2.99997H21C21.5523 2.99997 22 3.44769 22 3.99997V20C22 20.5523 21.5523 21 21 21H17V19ZM10.2 12L13 16H10.6L9 13.7143L7.39999 16H5L7.8 12L5 7.99997H7.39999L9 10.2857L10.6 7.99997H13L10.2 12Z"></path></svg>',
                    'ppt' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M2.85858 2.87732L15.4293 1.0815C15.7027 1.04245 15.9559 1.2324 15.995 1.50577C15.9983 1.52919 16 1.55282 16 1.57648V22.4235C16 22.6996 15.7761 22.9235 15.5 22.9235C15.4763 22.9235 15.4527 22.9218 15.4293 22.9184L2.85858 21.1226C2.36593 21.0522 2 20.6303 2 20.1327V3.86727C2 3.36962 2.36593 2.9477 2.85858 2.87732ZM4 4.73457V19.2654L14 20.694V3.30599L4 4.73457ZM17 19H20V4.99997H17V2.99997H21C21.5523 2.99997 22 3.44769 22 3.99997V20C22 20.5523 21.5523 21 21 21H17V19ZM5 7.99997H13V14H7V16H5V7.99997ZM7 9.99998V12H11V9.99998H7Z"></path></svg>',
                    'pdf' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M5 4H15V8H19V20H5V4ZM3.9985 2C3.44749 2 3 2.44405 3 2.9918V21.0082C3 21.5447 3.44476 22 3.9934 22H20.0066C20.5551 22 21 21.5489 21 20.9925L20.9997 7L16 2H3.9985ZM10.4999 7.5C10.4999 9.07749 10.0442 10.9373 9.27493 12.6534C8.50287 14.3757 7.46143 15.8502 6.37524 16.7191L7.55464 18.3321C10.4821 16.3804 13.7233 15.0421 16.8585 15.49L17.3162 13.5513C14.6435 12.6604 12.4999 9.98994 12.4999 7.5H10.4999ZM11.0999 13.4716C11.3673 12.8752 11.6042 12.2563 11.8037 11.6285C12.2753 12.3531 12.8553 13.0182 13.5101 13.5953C12.5283 13.7711 11.5665 14.0596 10.6352 14.4276C10.7999 14.1143 10.9551 13.7948 11.0999 13.4716Z"></path></svg>',
                    default => '',
                };

                return '<span class="size-6 *:w-full *:h-full text-zinc-400">'.$icon.'</span>';
            }
        );
    }

    /**
     * The scope for search
     */
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

        $ext = pathinfo(parse_url($endpoint, PHP_URL_PATH), PATHINFO_EXTENSION);
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
            'visibility' => $visibility,
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

        if ($this->is_resized || $this->is_converted_to_webp) {
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
