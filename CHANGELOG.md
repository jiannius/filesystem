# Changelog

All notable changes to `jiannius/filesystem`.

## 2.0.0 — 2026-11-23

### Changed (behaviour change — read before upgrading)

- **The upload route decides visibility and folder on the server.** `POST /__fs/upload` stores files with `fs.upload.visibility` (default `private`) in `fs.upload.folder` (default: the disk root). The `settings[visibility]` and `settings[folder]` request fields are ignored. Previously the client chose both, and the visibility default was `public`.
- **The upload route accepts only an allow-list of file types.** The extension (lowercase, from the client file name) must be in `fs.upload.extensions`, default `jpg, jpeg, png, gif, webp, pdf, csv, txt, xls, xlsx, doc, docx`, and the file must be at most `fs.upload.max_kb` kilobytes (default 10240). Failures are a 422 on the `file` field.
- **The upload route checks file content.** Images must be real images and PDFs must carry a `%PDF-` header within their first 1 KB. Plain-text files (`csv`, `txt`) are inspected up to 1 MB; other formats are inspected over their first 4 KB. Files whose detected MIME type or leading content is HTML, XML, SVG, JavaScript or PHP are refused whatever the extension, even if you add that extension to `fs.upload.extensions`.
- **The upload route is rate-limited by default.** `fs.routes.upload_middleware` now defaults to `['auth', 'throttle:30,1']`. Apps with a published `config/fs.php` do not pick this up; see Upgrading.
- `File::storeUpload()`, `File::store()` and the rest of the model API are unchanged. The restrictions apply to the route only, so server-side callers keep their own folder and visibility arguments.

### Added

- `fs.upload.authorize`: an optional callable (closure or invokable class name) that receives the `Request` before anything is stored. Returning `false` answers 403; only a literal `false` denies, so a hook that returns `null` allows the upload. It applies to `file` and `url[]` uploads.
- `Jiannius\Filesystem\Support\UploadGuard`, plus the overridable `uploadGuard()`, `uploadRules()`, `validateUpload()` and `authorizeUpload()` methods on `UploadController`, for hosts that need a different policy.

### Upgrading

1. Republish or edit `config/fs.php` to add the `upload` block (apps that never published the config get the defaults automatically).
2. If your app relied on uploads being public, set `'upload' => ['visibility' => 'public']`, or switch to private files and serve them with `$file->url` / `getImageUrl()`. Previously stored files are not changed.
3. If your front end sent `settings[folder]`, set `fs.upload.folder` instead. The server uses one folder for every upload through the route; for per-user or per-tenant folders, register your own route that calls `File::storeUpload($upload, $folder, $visibility)`.
4. If you accept other file types, add their extensions to `fs.upload.extensions` and raise `max_kb` as needed. HTML, XML, SVG, JavaScript and PHP stay refused.
5. **Add the throttle yourself if you have a published `config/fs.php`.** The new `['auth', 'throttle:30,1']` default only reaches apps without a published config: a published 1.x config keeps `'upload_middleware' => ['auth']`, and that value replaces the default, so the route stays unthrottled until you change it. Any host that sets `fs.routes.upload_middleware` should include `'throttle:30,1'` (or its own limit, for example `'throttle:120,1'`).
6. If you serve user uploads yourself, send `X-Content-Type-Options: nosniff` with them.

## 1.1.0 — 2026-11-10

### Changed (behaviour change — read before upgrading)

- **Server-side URL fetching is now opt-in through `fs.allow_url_fetch` (default `false`).** The upload route's `url[]` input and `File::storeImageUrl()` used to `Http::get()` any URL a logged-in user supplied. That is an SSRF vector: internal addresses and cloud metadata endpoints were reachable. With the new default, a `url[]` upload returns 422 (`url` validation error) and `storeImageUrl()` throws a `ValidationException`. Neither makes a request. **Apps that ingest image URLs must set `'allow_url_fetch' => true` in `config/fs.php`** (republish or add the key). YouTube ingestion is unaffected: it only calls noembed.com.
- **`getBase64()` reads disk-backed files from the disk** instead of fetching the file's own URL over HTTP. Rows with no disk (URL-only images) are still fetched over HTTP, but only when `fs.allow_url_fetch` is on. Otherwise the method returns null.

### Fixed

- The static factories (`store`, `storeUpload`, `storeYoutube`, `storeImageUrl`) call `static::` instead of `self::`, which makes their subclass-awareness explicit. PHP already forwarded late static binding through `self::`, so `Subclass::storeUpload()` was already returning the subclass. New tests pin this for a subclass configured as `fs.models.file`, including its model hooks.

## 1.0.3 — 2026-11-05

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
