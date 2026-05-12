# Changelog

All notable changes to `jiannius/filesystem`.

## 1.0.0 — 2026-05-12

### Breaking

- **Laravel 13 required.** Drops support for Laravel 12 and earlier. Hosts on Laravel 12 should stay pinned to `^0.2`.
- **PHP 8.3 minimum.**
- **Controllers no longer extend `App\Http\Controllers\Controller`.** This class was removed in Laravel 11's default scaffolding; the package now extends nothing.
- **Controllers no longer import `App\Models\File`.** They resolve the file model from `config('fs.models.file')` (default: the package's own `Jiannius\Filesystem\Models\File`).
- **`File::user()` no longer references `\App\Models\User`.** It resolves from `config('fs.models.user')` with a fallback to `config('auth.providers.users.model')`.
- **`File::getImageUrl()`** signature: `getImageUrl(array $config = [], ?int $valid = null)`. When `$valid === null` the method now reads `config('fs.image_url_default_ttl_days')` (default 7). Passing an integer `0` continues to produce a no-expiry signed URL. Hosts that previously passed `null` explicitly for no-expiry should switch to `0`.
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
