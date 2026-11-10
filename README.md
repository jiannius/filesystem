# Jiannius Filesystem

A Laravel package providing a unified `File` Eloquent model on top of Laravel's storage disks (`local`, `s3`, `do`, or any Flysystem driver), with on-the-fly image transformation via [league/glide](https://glide.thephpleague.com/), signed-URL serving, and a YouTube ingestion helper.

## Requirements

- PHP 8.3+
- Laravel 13

For Laravel 12 host apps, pin to `^0.2`.

## Installation

```bash
composer require jiannius/filesystem:^1.0
```

The service provider is auto-registered via Laravel's package discovery. The `files` table migration runs automatically on `php artisan migrate`.

Publish the config file:

```bash
php artisan vendor:publish --tag=fs-config
```

## Configuration

Everything is driven by `config/fs.php`:

```php
return [
    'models' => [
        'file' => \Jiannius\Filesystem\Models\File::class,
        'user' => null, // falls back to config('auth.providers.users.model')
    ],
    'routes' => [
        'enabled'           => true,
        'prefix'            => '__fs',
        'middleware'        => ['web'],
        'upload_middleware' => ['auth'],
    ],
    'glide' => [
        'cache_path'     => storage_path('app/private/glide-cache'),
        'max_image_size' => 2000 * 2000,
    ],
    'cloud_disks'                => ['s3', 'do'],
    'image_url_default_ttl_days' => 7,
    'allow_url_fetch'            => false,
];
```

`allow_url_fetch` controls whether the server downloads caller-supplied URLs (the upload route's `url[]` input, `File::storeImageUrl()`, and `getBase64()` on URL-only rows). It is off by default, because fetching arbitrary URLs from the server is an SSRF vector: internal hosts and cloud metadata endpoints become reachable. When it is off, `url[]` uploads get a 422 and `storeImageUrl()` throws a `ValidationException`. Turn it on only if you need it and trust the callers.

```php
```

## Usage

### HTTP upload endpoint

The package registers two routes:

- `POST /__fs/upload` — accepts a multipart `file` upload (and optional `settings`) or a `url[]` array. Behind `web` + `auth` middleware by default.
- `GET /__fs/img/{path}` — serves Glide-transformed images. Requires a signed URL. Responses are browser-cacheable (see [Rendering image URLs](#rendering-image-urls)).

Example upload from JavaScript:

```js
const fd = new FormData();
fd.append('file', fileInput.files[0]);
fd.append('settings[folder]', 'avatars');
fd.append('settings[visibility]', 'public');

await fetch('/__fs/upload', { method: 'POST', body: fd });
```

The response is the JSON-serialized `File` record.

### Programmatic storage

```php
use Jiannius\Filesystem\Models\File;

// From an uploaded file
$file = File::storeUpload($request->file('photo'), 'avatars', 'public');

// From a YouTube URL — fetches title + thumbnail via noembed.com
$video = File::storeYoutube('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

// From a remote image URL — downloads, captures dimensions (requires fs.allow_url_fetch)
$image = File::storeImageUrl('https://example.com/photo.jpg');
```

### Rendering image URLs

```php
// Returns a signed URL valid for the configured TTL (default 7 days, min.)
$file->getImageUrl();

// Custom TTL in days
$file->getImageUrl([], 30);

// No expiry
$file->getImageUrl([], 0);

// With Glide transformations
$file->getImageUrl(['w' => 400, 'h' => 400, 'fit' => 'crop']);
```

#### Stable, browser-cacheable URLs

The expiry is rounded **up** to an epoch-aligned bucket of N days (N = the TTL), so the URL for a given file and transformation is identical on every render inside a bucket, and changes only when the next bucket starts. A URL is therefore valid for **between N and 2N days** (not exactly N). `$valid = 0` still produces a non-expiring URL.

`GET /__fs/img/{path}` responses carry:

- `Cache-Control: private, max-age=<seconds until the URL's expires>` — cached by the browser only (never shared caches, since access goes through `$file->auth()`), and never past the signature's expiry. A URL with no `expires` falls back to 7 days.
- `ETag` (from the path, the transformation params and the row's `updated_at`) and `Last-Modified` (the row's `updated_at`).

A conditional request (`If-None-Match` / `If-Modified-Since`) is answered with `304 Not Modified` after the `auth()` check but before any Glide work or blob read, so repeat views cost almost nothing. `auth()` is still evaluated on every request, so a 403 is never masked by a cached ETag.

Glide transformation parameters (`w`, `h`, `fit`, `q`, `fm`, etc.) are passed straight through. See [Glide's API reference](https://glide.thephpleague.com/2.0/api/quick-reference/) for the full list.

### File model attributes

Useful accessors on the `File` model:

| Attribute     | Description |
|---------------|-------------|
| `size`        | Human-readable file size (e.g. `"1.2 MB"`) |
| `is_image`    | True if MIME starts with `image/` |
| `is_video`    | True if MIME starts with `video/` |
| `is_audio`    | True if MIME starts with `audio/` |
| `is_youtube`  | True if `mime === 'youtube'` |
| `is_file`     | True if none of the above (generic document) |
| `filename`    | The on-disk filename (last segment of `path`) |
| `url`         | Public URL — resolves per-disk (local-driver disk that serves itself → signed URL, bucketed over `image_url_default_ttl_days`; other local-driver disk, e.g. `public` → its `url`; cloud public → disk URL; cloud private → 1h temporary URL) |
| `type`        | Coarse type label: `image`, `video`, `audio`, `youtube`, `pdf`, `word`, `excel`, `ppt`, `text`, `svg`, `file` |
| `icon`        | Inline SVG markup matching `type` |

### Extending the File model

If you want to add custom methods or relations:

```php
// app/Models/File.php
namespace App\Models;

class File extends \Jiannius\Filesystem\Models\File
{
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function auth(): bool
    {
        return $this->user_id === auth()->id();
    }
}
```

Then point the config at your subclass:

```php
// config/fs.php
'models' => [
    'file' => \App\Models\File::class,
],
```

The `auth()` method is called by `ImageController` before serving any image — override it to enforce per-file access control.

### Cloud disks

For `s3` or `do` (DigitalOcean Spaces) disks, configure them as normal Laravel filesystems in `config/filesystems.php`. The package's `url` accessor and production-delete guard read from `config('fs.cloud_disks')` — add custom disk names there if you use anything beyond `s3`/`do`.

```php
// config/fs.php
'cloud_disks' => ['s3', 'do', 'r2'],
```

### Production-delete guard

When a `File` row is created in production, its `env` column is set to `production`. Deleting that row from a non-production environment (e.g. on a staging copy of the production database) throws an exception — preventing accidental wipes of live media from a dev machine.

This applies only to disks listed in `config('fs.cloud_disks')`. Local files are unaffected.

### Disabling the package routes

If you want to register your own routes:

```php
// config/fs.php
'routes' => ['enabled' => false],
```

Then wire up `Jiannius\Filesystem\Controllers\UploadController` and `Jiannius\Filesystem\Controllers\ImageController` in your own `routes/*.php` files. Keep the route names `__fs.upload` and `__fs.image` if you want `$file->getImageUrl()` to keep working without modification.

## Upgrading from 0.2.x

See [CHANGELOG.md](CHANGELOG.md) for the 1.0.0 migration guide.

## License

MIT. See [LICENSE.md](LICENSE.md).
