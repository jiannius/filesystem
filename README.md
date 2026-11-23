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
        'upload_middleware' => ['auth', 'throttle:30,1'],
    ],
    'upload' => [
        'visibility' => 'private',
        'folder'     => '',
        'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'csv', 'txt', 'xls', 'xlsx', 'doc', 'docx'],
        'max_kb'     => 10240,
        'authorize'  => null, // callable or invokable class name; return false to answer 403
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

- `POST /__fs/upload` — accepts a multipart `file` upload or a `url[]` array. Behind `web`, `auth` and `throttle:30,1` middleware by default (see [Upload policy](#upload-policy)).
- `GET /__fs/img/{path}` — serves Glide-transformed images. Requires a signed URL. Responses are browser-cacheable (see [Rendering image URLs](#rendering-image-urls)).

Example upload from JavaScript:

```js
const fd = new FormData();
fd.append('file', fileInput.files[0]);

await fetch('/__fs/upload', { method: 'POST', body: fd });
```

The response is the JSON-serialized `File` record.

#### Upload policy

The server decides how an upload is stored. The route is configured under `fs.upload`:

- **`visibility`** (default `private`) and **`folder`** (default empty) are applied to every upload. A `settings[visibility]` or `settings[folder]` sent by the client is ignored. Set `'visibility' => 'public'` if the route should keep storing public files.
- **`extensions`** is the allow-list of file types, compared in lowercase and taken from the client file name. Anything else is answered with a 422.
- **`max_kb`** (default `10240`) is the largest accepted file, in kilobytes. It only takes effect within PHP's own limits: `upload_max_filesize` and `post_max_size` also apply, and a request over those never reaches the route.
- **Content check.** Images must be real images and PDFs must carry a PDF header within their first 1 KB. Regardless of the allow-list, a file whose detected type or leading content is HTML, XML, SVG, JavaScript or PHP is refused, so adding `svg` or `html` to `extensions` has no effect.
- **`authorize`** is an optional callable (a closure, or an invokable class name resolved from the container) that receives the `Request`. Only a literal `false` denies the upload; a hook that returns `null` (for example by forgetting to `return`) allows it. It runs before anything is stored, for both `file` and `url[]` uploads. Prefer a class name if you run `config:cache`, since closures cannot be cached.
- **Throttle.** `routes.upload_middleware` defaults to `['auth', 'throttle:30,1']`. This is only the default for apps without a published config. If your app has a published `config/fs.php`, it keeps its own `upload_middleware` value (for 1.x that is `['auth']`), so add `'throttle:30,1'` there yourself.
- **Visibility.** `visibility` only has an effect on the cloud disks (`s3`, `do`), where it is passed to the disk and decides between a public URL and a temporary signed URL. On a local-driver disk it is recorded in the `visibility` column only: the file is written the same way, and `$file->url` is resolved from the disk (a signed URL when the disk supports temporary URLs, otherwise the disk's plain URL), not from the column.
- **Serving uploads.** If you serve user uploads yourself, send `X-Content-Type-Options: nosniff` with them.

The checks live in `Jiannius\Filesystem\Support\UploadGuard` and are applied by `UploadController` only. To change them, subclass the controller and override `uploadGuard()` or `uploadRules()`, then register your subclass as the route (see [Disabling the package routes](#disabling-the-package-routes)). `File::storeUpload()` is not restricted: code in your own app that calls it passes its own folder and visibility and is not subject to the allow-list.

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

## Upgrading to 2.0

Uploads through `POST /__fs/upload` are now stored `private`, in the configured folder, and limited to an allow-list of file types. See the 2.0.0 entry in [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE.md](LICENSE.md).
