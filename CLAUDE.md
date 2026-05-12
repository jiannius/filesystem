# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`jiannius/filesystem` is a Laravel package (PHP, PSR-4 under `Jiannius\Filesystem\`) providing a unified file model on top of Laravel's storage disks (`local`, `do`, `s3`) with on-the-fly image transformation via `league/glide`. It is consumed by host Laravel applications — it is not a standalone app and has no test suite configured.

The Laravel service provider is auto-registered via `composer.json`'s `extra.laravel.providers`. `FilesystemServiceProvider::boot()` loads `routes/web.php` and `database/migrations/` into the host app.

## Common commands

```bash
composer install            # install dependencies
composer update             # bump composer.lock
composer dump-autoload      # regenerate PSR-4 autoload after adding classes
composer test                                       # run the Orchestra Testbench feature suite
vendor/bin/phpunit tests/Feature/UploadTest.php     # run a single test file
```

The Orchestra Testbench feature suite is wired up under `tests/` and runnable via `composer test` (or `vendor/bin/phpunit` directly). No lint or format scripts are configured.

When bumping the package version, update **both** `composer.json` `version` and commit with a `Bump version to X.Y.Z` message (matches existing history, e.g. `9559b54`).

## Architecture

### Config-driven bindings (1.0+)

The package owns its own `Jiannius\Filesystem\Models\File`. Controllers and the model itself resolve dependent classes from `config('fs.*')` rather than hardcoding host-app classes:

- `config('fs.models.file')` — defaults to the package's own `File`. Hosts that want to extend the model point this at their `App\Models\File extends Jiannius\Filesystem\Models\File`.
- `config('fs.models.user')` — null by default; falls back to `config('auth.providers.users.model')`. Controls the target of `File::user()`.
- `config('fs.routes.*')` — toggle/rename the package's two routes if you want to register your own.
- `config('fs.glide.*')` — Glide cache path and max image size.
- `config('fs.cloud_disks')` — disks subject to the production-delete guard.

Pre-1.0 versions (`0.2.x` and earlier) hardcoded `App\Models\File`, `App\Models\User`, and `App\Http\Controllers\Controller` references inside the package. Those required the host app to scaffold matching classes. The 1.0 config pattern replaces all of that.

### Routes (registered on `web` middleware)

- `POST /__fs/upload` → `UploadController` — requires `auth`. Accepts either `file` (uploaded file) + `settings`, or `url[]` (array of image URLs to ingest).
- `GET /__fs/img/{path}` → `ImageController` — requires `signed` middleware. The `{path}` segment uses `where('path', '.*')` to allow slashes. Query params (besides `expires`/`signature`) are forwarded to Glide as image manipulation directives (`w`, `h`, `fit`, etc.).

### File model storage flow

`File::store($upload, $youtube, $url)` is the single entrypoint and dispatches to one of:

- `storeUpload($upload, $folder, $visibility)` — uploads to `config('filesystems.default')` disk. Extension is parsed from the **client original name** (not Laravel's `extension()`, which can return `.bin` after content-sniffing). The file is saved as `random(30).ext`; the disk's configured `folder` (from filesystem config) is prefixed to the requested folder.
- `storeYoutube($url)` — extracts the video ID via regex, fetches metadata from `noembed.com`, stores with `mime = 'youtube'` and no `path`/`disk`.
- `storeImageUrl($url)` — calls `getimagesize($url)` on a remote URL, stores width/height/mime with no `disk`/`path`.

The `url` attribute accessor (`src/Models/File.php:184`) resolves differently per disk: `local` → `asset('storage/...')`, `do`/`s3` private → temporary signed URL (1h), `do`/`s3` public → disk public URL.

### Image URL signing + Glide

`File::getImageUrl($config, $valid = 7)` returns a `URL::temporarySignedRoute('__fs.image', ...)` link valid for `$valid` days (or no expiry if `$valid` is falsy — see commit `a4eaf41`). Glide config is hardcoded in `getGlideServer()`:

- cache dir: `storage_path('app/private/glide-cache')` (commit `72f0067` moved this)
- `max_image_size`: `2000*2000`
- source: the file's own disk driver

When a file is deleted, both the disk file and the Glide cache for its path are removed (`deleteFromDisk()`).

### Production-delete guard

`preventProductionDelete()` (called from the `deleting` model event) throws if the file's stored `env` is `production` but the current app environment is not. This only applies to `do`/`s3` disks. Don't bypass this — it's there to stop dev/staging from wiping prod media.

### MIME constant

`File::MIME` is a hand-curated extension→mime map used to override Laravel's content-sniffing during upload (because sniffing returns `.bin` for some files). Keep this in sync if you handle a new file type.

### Migration

`database/migrations/filesystem_0001_files_table.php` creates the `files` table only if it doesn't already exist (`Schema::hasTable` guard) — safe to re-run. Primary key is a ULID (`HasUlids` trait on the model).
