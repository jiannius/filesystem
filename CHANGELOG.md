# Changelog

All notable changes to `jiannius/filesystem`.

## Unreleased

### Fixed

- **`File::url` for locally stored files no longer 403s on Laravel 11+.** It returned `asset('storage/'.$path)`, which is only right for a `public` disk symlinked to `public/storage`. The default `local` disk (root `storage/app/private`, `serve => true`) serves `/storage/*` through a route that only accepts signed URLs. The URL is now resolved from the disk: a disk that provides temporary URLs gets a signed one, bucketed over `fs.image_url_default_ttl_days` like image URLs (stable and browser-cacheable; a falsy value falls back to 7 days); any other `local`-driver disk, such as `public`, gets its plain `url()`, which previously returned null. The check is on the disk's driver, not its name. Cloud disks are unchanged.

## 1.0.2 — 2026-11-02

### Changed

- **Image URLs are stable and browser-cacheable.** `getImageUrl()` used to sign with `now()->addDays($valid)`, so the URL changed on every render and no browser could cache it. Every page view re-requested every thumbnail through PHP and Glide. The expiry is now rounded up to an epoch-aligned bucket of `$valid` days, so the URL is identical for every render inside a bucket. A URL now stays valid for between `$valid` and `2 × $valid` days: the default of 7 gives 7 to 14 days. `$valid = 0` (no expiry) is unchanged.
- **`ImageController` sends cache headers.** A 200 now carries `Cache-Control: private, max-age=<seconds until the URL's expires>` (7 days when there's no expiry), an `ETag` (built from the path, the Glide params and the row's `updated_at`) and a `Last-Modified` (from `updated_at`).
- **Conditional requests get a 304.** A matching `If-None-Match` or `If-Modified-Since` is answered with a 304 after `$file->auth()` and before Glide runs or the blob is read. A failed `auth()` is still a 403, and a missing blob is still a 404 on a full request.

## 1.0.1 — 2026-09-13

### Fixed

- **A missing blob now returns 404 instead of a 500.** Glide throws `FileNotFoundException` when the source image is gone — a blob deleted out from under its row, or never copied into an environment. `ImageController` let it bubble, so every such thumbnail became a 500 plus a logged exception, and a listing of many such rows produced a burst of them. The controller now catches it and aborts 404, which is what an absent file means. Sibling of the existing 403 case.

## 1.0.0 — 2026-05-12

### Breaking

- **Laravel 13 required.** Drops support for Laravel 12 and earlier. Hosts on Laravel 12 should stay pinned to `^0.2`.
- **PHP 8.3 minimum.**
- **Controllers no longer extend `App\Http\Controllers\Controller`.** This class was removed in Laravel 11's default scaffolding; the package now extends nothing.
- **Controllers no longer import `App\Models\File`.** They resolve the file model from `config('fs.models.file')` (default: the package's own `Jiannius\Filesystem\Models\File`).
- **`File::user()` no longer references `\App\Models\User`.** It resolves from `config('fs.models.user')` with a fallback to `config('auth.providers.users.model')`.
- **`File::getImageUrl()`** signature: `getImageUrl(array $config = [], ?int $valid = null)`. The second arg is now strictly typed `?int` — callers passing `false`, `true`, or string values (e.g. `'7'`) will hit a `TypeError` and must be updated to pass `null`, an int, or `0`. When `$valid === null` the method reads `config('fs.image_url_default_ttl_days')` (default 7). Passing `0` continues to produce a no-expiry signed URL. Hosts that previously passed `null` explicitly for no-expiry should switch to `0`.
- **`ImageController`** now returns a `Symfony\Component\HttpFoundation\StreamedResponse` instead of relying on Glide's `outputImage()` writing to stdout. Same headers (`Content-Type`, `Content-Length`), same bytes — but now routed through Laravel's response pipeline (testable, middleware-aware).
- **`UploadController::saveUrls` and `saveUploads`** are now `protected` (were `public`). Internal dispatch helpers — should not be called from outside the controller.
- Dropped dependencies: `doctrine/dbal`, `intervention/image` (both were unused in the package source).

### Added

- `config/fs.php` — publishable via `php artisan vendor:publish --tag=fs-config`. Controls model bindings, route registration, Glide settings, cloud-disk guard list, and signed-URL TTL.
- Orchestra Testbench test harness in `tests/` (22 tests covering upload, image route, deletion, production-delete guard, YouTube ingestion, config bindings).

### Fixed

- `UploadController::saveUploads` previously passed `$settings` array as the 2nd positional arg of `File::store($upload, $youtube, ...)`, which misrouted it as a YouTube URL. Settings (`folder`, `visibility`) now correctly thread through to `File::storeUpload`.
- `UploadController::saveUrls` previously passed URL strings in the `$upload` slot of `File::store`, which would have crashed in `storeUpload` when called against a string. Now uses the named `url:` argument so `File::storeImageUrl` dispatches correctly.

### Migration guide

1. `composer require jiannius/filesystem:^1.0`
2. `php artisan vendor:publish --tag=fs-config` to publish `config/fs.php`.
3. If your app extends the model as `App\Models\File extends Jiannius\Filesystem\Models\File`, set `'models.file' => \App\Models\File::class` in the new config.
4. If your `app/Http/Controllers/Controller.php` only existed to satisfy this package, you can delete it.
5. Route names `__fs.upload` and `__fs.image` are preserved — `route('__fs.image', ['path' => ...])` continues to work.
