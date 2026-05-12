# Jiannius Filesystem — Laravel 13 upgrade & 1.0.0 release

**Date:** 2026-05-12
**Status:** Approved (pending user spec-review gate)
**Target release:** `1.0.0` (no `v` prefix)

## Goals

1. Make the package work on Laravel 13. All consuming host apps will be moved to L13 + Livewire 4; the package must support them first.
2. Decouple the package from host-app classes (`App\Models\File`, `App\Models\User`, `App\Http\Controllers\Controller`) so it works on a fresh L13 install without forcing the host to add scaffolding L11+ removed.
3. Add a published config file so cache paths, model bindings, and route registration are user-controllable.
4. Replace fragile `file_get_contents` / `getimagesize` remote calls with a real HTTP client (timeouts, error handling).
5. Establish a `testbench`-based test harness so future Laravel upgrades can be verified without manual click-throughs.
6. Tag `1.0.0` cleanly so host apps still on Laravel 12 stay on `^0.2` and are unaffected.

## Non-goals

- No DB schema change. `files` table stays exactly as it is in `database/migrations/filesystem_0001_files_table.php`.
- No change to public route paths. `__fs/upload` and `__fs/img/{path}` keep their URIs and middleware names. Only their *registration* becomes config-toggleable.
- No Livewire components added. Package stays backend-only.
- No changes to the `File::MIME` constant — the inline comment in `storeUpload` justifies why hand-curated mapping is correct (Laravel's mime sniffing returns `application/octet-stream` for some files).
- No rewrite of Glide invocation semantics — only its configuration source moves to `config/fs.php`.

## Composer constraints (`composer.json`)

```json
"require": {
    "php": "^8.2",
    "illuminate/support": "^13.0",
    "illuminate/database": "^13.0",
    "illuminate/http": "^13.0",
    "illuminate/routing": "^13.0",
    "league/flysystem-aws-s3-v3": "^3.0",
    "league/glide": "^3.0",
    "symfony/http-client": "^6.0 | ^7.0"
}
```

**Removed:**

- `doctrine/dbal: ^3.6` — Laravel 10+ no longer requires it for schema operations. Audit confirmed no direct usage in `src/`.
- `intervention/image: ^3` — Glide handles all image transforms in this package. Audit confirmed no direct usage in `src/`.

**Widened:**

- `symfony/http-client` to `^6.0 | ^7.0` because Laravel 13 ships Symfony 7.

**Version bump:**

- `"version": "1.0.0"`

## New file: `config/fs.php`

Published to the host app's `config/fs.php`. Filename and key chosen to (a) avoid visual confusion with Laravel's built-in `config/filesystems.php`, and (b) match the existing `__fs` route prefix.

Inline comments document every option. Structure:

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Eloquent model bindings
    |--------------------------------------------------------------------------
    |
    | If your host app extends Jiannius\Filesystem\Models\File with its own
    | App\Models\File, set `file` to that class. Leave null/default to use
    | the package's model directly. `user` falls back to
    | config('auth.providers.users.model') when null.
    |
    */
    'models' => [
        'file' => \Jiannius\Filesystem\Models\File::class,
        'user' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Route registration
    |--------------------------------------------------------------------------
    |
    | Set `enabled` to false if you want to register your own routes instead
    | of using the package defaults. `prefix` controls the URI prefix for
    | both upload and image routes. `middleware` applies to both routes;
    | `upload_middleware` is added to the upload route only.
    |
    */
    'routes' => [
        'enabled'           => true,
        'prefix'            => '__fs',
        'middleware'        => ['web'],
        'upload_middleware' => ['auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Glide image manipulation
    |--------------------------------------------------------------------------
    |
    | `cache_path` is where Glide writes transformed image variants. It is
    | also purged when the source File model is deleted. `max_image_size`
    | caps the pixel dimensions Glide will process (width * height).
    |
    */
    'glide' => [
        'cache_path'     => storage_path('app/private/glide-cache'),
        'max_image_size' => 2000 * 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloud disks subject to the production-delete guard
    |--------------------------------------------------------------------------
    |
    | When a File model with env=production is deleted from a non-production
    | environment, an exception is thrown — but only if its disk is in this
    | list. Add custom disk names here if you use anything beyond s3 / do.
    |
    */
    'cloud_disks' => ['s3', 'do'],

    /*
    |--------------------------------------------------------------------------
    | Default TTL (in days) for signed image URLs
    |--------------------------------------------------------------------------
    |
    | Used by File::getImageUrl() when no explicit expiry is passed. Set to
    | null to default to non-expiring signed URLs.
    |
    */
    'image_url_default_ttl_days' => 7,

];
```

## `FilesystemServiceProvider` changes

```php
public function register(): void
{
    $this->mergeConfigFrom(__DIR__.'/../config/fs.php', 'fs');
}

public function boot(): void
{
    if (config('fs.routes.enabled', true)) {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }

    $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

    $this->publishes([
        __DIR__.'/../config/fs.php' => config_path('fs.php'),
    ], 'fs-config');
}
```

## `routes/web.php` changes

Route prefix, middleware, and controller class read from config. Existing path segments (`upload`, `img/{path}`) are kept hardcoded — they are stable URIs, not user-facing config.

```php
use Illuminate\Support\Facades\Route;
use Jiannius\Filesystem\Controllers\ImageController;
use Jiannius\Filesystem\Controllers\UploadController;

$prefix = config('fs.routes.prefix', '__fs');
$middleware = config('fs.routes.middleware', ['web']);
$uploadMiddleware = array_merge($middleware, config('fs.routes.upload_middleware', ['auth']));

Route::post($prefix.'/upload', UploadController::class)
    ->middleware($uploadMiddleware)
    ->name('__fs.upload');

Route::get($prefix.'/img/{path}', ImageController::class)
    ->middleware(array_merge($middleware, ['signed']))
    ->where('path', '.*')
    ->name('__fs.image');
```

(Route names kept as `__fs.upload` / `__fs.image` for BC with any host code that calls `route('__fs.image', ...)`.)

## Controller changes

Both `UploadController` and `ImageController`:

- **Drop** `extends App\Http\Controllers\Controller`. Neither controller uses any base-controller feature; the parent class doesn't exist on a fresh Laravel 11+ install. Use no parent class.
- **Drop** `use App\Models\File`. Resolve the file model via `config('fs.models.file')`.

Example (`ImageController`):

```php
namespace Jiannius\Filesystem\Controllers;

class ImageController
{
    public function __invoke()
    {
        $fileClass = config('fs.models.file');
        $path = request()->path;

        $file = $fileClass::query()
            ->withMime('image/*')
            ->where('path', $path)
            ->firstOrFail();

        if (!$file->auth()) abort(403);

        return $file->getGlideServer()->outputImage($path, request()->except(['expires', 'signature']));
    }
}
```

`UploadController` likewise resolves the model class from config.

## `Models\File` changes

1. **`user()` relation**: resolve target via `config('fs.models.user') ?? config('auth.providers.users.model')`. Removes the hard reference to `\App\Models\User`.

2. **`getGlideServer()`**: read `cache_path` and `max_image_size` from `config('fs.glide.*')`.

3. **`preventProductionDelete()`**: read the cloud-disk list from `config('fs.cloud_disks', ['s3', 'do'])`.

4. **`getImageUrl()`**: signature becomes `getImageUrl(array $config = [], ?int $valid = null)`. When `$valid` is `null`, the method reads `config('fs.image_url_default_ttl_days', 7)` and uses that. Passing `$valid = 0` (or any falsy value other than null) preserves the existing "no expiry" semantics added in commit `a4eaf41`. Callers that previously passed an explicit int continue to behave identically.

5. **Remote HTTP calls** — replace with `Http` facade and explicit timeouts:
   - `storeYoutube`: replace `file_get_contents('https://noembed.com/embed?...')` with `Http::timeout(5)->get('https://noembed.com/embed', ['dataType' => 'json', 'url' => $url])->json()`. Keep the `rescue()` wrapper so failures degrade gracefully (returns a record without thumbnail metadata).
   - `storeImageUrl`: download the image body via `Http::timeout(5)->get($url)`, write the response body to a `tmpfile()` stream, then call `getimagesize($tmpPath)` on the local temp path. This replaces the remote `getimagesize($url)` (which relies on PHP's stream wrappers and has no real timeout control). Wrap in `rescue()`.
   - `getBase64`: replace `file_get_contents($this->url)` with `Http::timeout(10)->get($this->url)->body()`.

6. **Image content reads**: continue to use `rescue(...)` for all remote calls so a transient network failure during ingestion doesn't 500 the upload endpoint.

7. **MIME constant**: unchanged.

## Test harness (`tests/`)

`orchestra/testbench` is already declared as a dev dep but unused. Add a `phpunit.xml` and `tests/` directory with the following structure:

```
tests/
  TestCase.php              # extends Orchestra\Testbench\TestCase, registers FilesystemServiceProvider
  Feature/
    UploadTest.php          # POST /__fs/upload with a fake file, asserts file row + storage write
    ImageRouteTest.php      # signed-URL generation + GET /__fs/img/... happy path + 403 on bad sig
    DeleteCachePurgeTest.php # delete a File model, assert disk and glide-cache entries are gone
    ProductionGuardTest.php # env=production File on s3 disk + non-prod app environment → throws
    YoutubeStoreTest.php    # storeYoutube with a faked Http response → record created
    ConfigBindingsTest.php  # host-side custom File subclass via config('fs.models.file') is resolved by controllers
```

- Use `Storage::fake()` for disk assertions.
- Use `Http::fake()` for noembed and remote-image-url calls.
- Tests target the `local` disk by default to avoid AWS credentials.

Add to `composer.json`:

```json
"scripts": {
    "test": "vendor/bin/phpunit"
}
```

## Versioning & tagging

- **Frozen line: `0.2.5`** (existing tip on `main` right now). Hosts pinned to `^0.2` keep getting the same artifact. No backports planned.
- **New line: `1.0.0`**. Cut from `main` after all changes above land. No `v` prefix going forward (matches the most recent tag style).
- **No RC.** Verification via the testbench harness + a single manual smoke test in one host app is enough; tag `1.0.0` stable directly.

## Host-app migration path (changelog entry)

Documented in the package README / a `CHANGELOG.md`:

1. `composer require jiannius/filesystem:^1.0`
2. `php artisan vendor:publish --tag=fs-config` → creates `config/fs.php`
3. If your app had `App\Models\File extends Jiannius\Filesystem\Models\File`, set `'models.file' => \App\Models\File::class` in the new config. Otherwise the package's own model is used directly.
4. If your app had a stub `App\Http\Controllers\Controller` that existed *only* to satisfy this package, you can delete it.
5. If you used `route('__fs.upload')` or `route('__fs.image', ...)`: no change, names preserved.

## Verification checklist (run before tagging 1.0.0)

- [ ] `composer install` from a clean clone resolves on PHP 8.2+ with Laravel 13.
- [ ] `vendor/bin/phpunit` passes all feature tests.
- [ ] One host app upgraded locally: upload a file, render its image URL, fetch the image, delete the record, confirm Glide cache purge happened.
- [ ] `composer.json` `version` = `1.0.0`.
- [ ] Tag `1.0.0` created and pushed.
