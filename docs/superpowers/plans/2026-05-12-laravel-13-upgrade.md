# Jiannius Filesystem — Laravel 13 Upgrade & 1.0.0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cut a Laravel 13–compatible `1.0.0` release of `jiannius/filesystem`. Decouple from host-app `App\Models\File`, `App\Models\User`, and `App\Http\Controllers\Controller` via a published `config/fs.php`. Replace remote `file_get_contents` calls with the `Http` facade. Add a `testbench` test suite so future Laravel upgrades can be verified without manual click-throughs.

**Architecture:** Service provider merges/publishes `config/fs.php`. Controllers and routes resolve the file model and middleware from config. The `File` model reads Glide settings, cloud-disk list, image-URL TTL, and the user-relation target from config. Remote HTTP calls move to the `Http` facade with explicit timeouts. The `0.2.x` line is frozen for Laravel 12 hosts; new work ships as `1.0.0`.

**Tech Stack:** PHP 8.2+, Laravel 13 (`illuminate/*: ^13.0`), League Glide 3, League Flysystem AWS S3 v3, Symfony HttpClient 6|7, Orchestra Testbench (test-only), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-05-12-laravel-13-upgrade-design.md` (commit `800724a`).

---

## File Structure

**New files:**
- `config/fs.php` — published config, drives model bindings, route registration, Glide cache, cloud-disk list, image-URL TTL.
- `phpunit.xml` — PHPUnit config for the package (bootstrap, coverage, environment).
- `tests/TestCase.php` — base test case extending `Orchestra\Testbench\TestCase`, registers the service provider and an in-memory SQLite DB.
- `tests/Feature/SmokeTest.php` — confirms the harness boots, the package's migration ran, and `config('fs.*')` resolves.
- `tests/Feature/ConfigBindingsTest.php` — confirms controllers resolve the file model class from config and that a host-side subclass is honored.
- `tests/Feature/UploadTest.php` — POST `/__fs/upload` with `UploadedFile::fake()`, assert DB row + disk write.
- `tests/Feature/ImageRouteTest.php` — `URL::temporarySignedRoute('__fs.image', ...)` + GET it; assert 200 image response and 403 on tampering.
- `tests/Feature/DeleteCachePurgeTest.php` — delete a `File` model, assert disk file and Glide cache directory entry are gone.
- `tests/Feature/ProductionGuardTest.php` — `env=production` File on `s3` disk + non-prod app environment → throws.
- `tests/Feature/YoutubeStoreTest.php` — `Http::fake()` for noembed; `File::storeYoutube($url)` returns a model with the expected `data` payload.
- `CHANGELOG.md` — host-app migration notes for 1.0.0.

**Files modified:**
- `composer.json` — drop `doctrine/dbal`, `intervention/image`; add explicit `illuminate/*: ^13.0`; widen `symfony/http-client` to `^6.0 | ^7.0`; bump PHP to `^8.2`; add `phpunit` dev dep; add `scripts.test`; bump `version` to `1.0.0` (final task only).
- `src/FilesystemServiceProvider.php` — `mergeConfigFrom`, `publishes`, gate routes on `config('fs.routes.enabled')`.
- `routes/web.php` — read prefix/middleware from config.
- `src/Controllers/UploadController.php` — drop `extends App\Http\Controllers\Controller` and `use App\Models\File`. Resolve file class via `config('fs.models.file')`.
- `src/Controllers/ImageController.php` — same.
- `src/Models/File.php` — config-resolved `user()` target, `preventProductionDelete` disk list, Glide cache + max image size, `getImageUrl` default TTL, replace remote HTTP calls (`storeYoutube`, `storeImageUrl`, `getBase64`).
- `CLAUDE.md` — update architecture notes to reflect config-based bindings; remove the "consumer must extend `App\Models\File`" framing.

---

## Task 1: Set up Orchestra Testbench harness

**Files:**
- Modify: `composer.json` (add `phpunit/phpunit` dev dep, `test` script). `orchestra/testbench` already declared.
- Create: `phpunit.xml`
- Create: `tests/TestCase.php`
- Create: `tests/Feature/SmokeTest.php`

- [ ] **Step 1: Add PHPUnit dev dep and test script to `composer.json`**

Modify the `require-dev` block and add a `scripts` block. After this step, `require-dev` should contain both packages and a `scripts.test` entry.

```json
"require-dev": {
    "orchestra/testbench": "^10.0",
    "phpunit/phpunit": "^11.0"
},
"scripts": {
    "test": "vendor/bin/phpunit"
}
```

Note: `orchestra/testbench` version is bumped to `^10.0` because Testbench follows Laravel's major version (Testbench 10.x = Laravel 13.x). PHPUnit 11 is Laravel 13's pinned version.

- [ ] **Step 2: Run `composer update` and verify install succeeds**

Run: `composer update --with-dependencies`
Expected: succeeds, no resolver errors. `vendor/bin/phpunit` exists.

- [ ] **Step 3: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
>
    <testsuites>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="DB_CONNECTION" value="testing"/>
    </php>
</phpunit>
```

- [ ] **Step 4: Add `tests/` autoload entry in `composer.json`**

Add an `autoload-dev` block under the existing `autoload`:

```json
"autoload-dev": {
    "psr-4": {
        "Jiannius\\Filesystem\\Tests\\": "tests/"
    }
}
```

Then run: `composer dump-autoload`
Expected: succeeds.

- [ ] **Step 5: Create `tests/TestCase.php`**

```php
<?php

namespace Jiannius\Filesystem\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Jiannius\Filesystem\FilesystemServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [FilesystemServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('filesystems.default', 'local');
        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => storage_path('app'),
            'serve' => true,
            'throw' => false,
        ]);
    }
}
```

- [ ] **Step 6: Write the smoke test**

```php
<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Jiannius\Filesystem\Tests\TestCase;

class SmokeTest extends TestCase
{
    public function test_service_provider_boots_and_migration_runs(): void
    {
        $this->assertTrue(Schema::hasTable('files'));
    }

    public function test_routes_are_registered(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('__fs.upload'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('__fs.image'));
    }
}
```

- [ ] **Step 7: Run the test and verify it passes**

Run: `vendor/bin/phpunit tests/Feature/SmokeTest.php`
Expected: 2 tests pass. (If the model still references `\App\Models\User` — which it does at this stage — the migration doesn't load that class, so the boot must still succeed. If it doesn't, debug before continuing.)

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock phpunit.xml tests/
git commit -m "test: add orchestra/testbench harness with smoke test

Sets up the test infrastructure so subsequent refactor steps can be
validated automatically. Migration auto-runs via the service provider;
routes are confirmed registered.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Modernize `composer.json` dependencies

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Write a failing test for L13 constraint**

There isn't a runtime test for composer constraints. Instead, the verification is `composer validate` plus running the existing smoke test on the new deps.

- [ ] **Step 2: Update `composer.json` `require` block**

Replace the `require` block entirely:

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

Removed: `doctrine/dbal`, `intervention/image`. Both confirmed unused in `src/` by source audit during brainstorming.

- [ ] **Step 3: Run `composer update` and verify it resolves**

Run: `composer update --with-dependencies`
Expected: resolver succeeds, no conflicts. If a transitive dep pulls in `doctrine/dbal` anyway, that's fine — we're only removing the direct require.

- [ ] **Step 4: Re-run smoke tests**

Run: `vendor/bin/phpunit tests/Feature/SmokeTest.php`
Expected: 2 tests still pass on the new dep set.

- [ ] **Step 5: Validate composer.json**

Run: `composer validate --strict`
Expected: `./composer.json is valid` (or only warnings about description format — those are fine).

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock
git commit -m "deps: target Laravel 13, drop unused doctrine/dbal and intervention/image

doctrine/dbal was used historically for schema operations on older
Laravel; not referenced anywhere in src/. intervention/image was never
imported — Glide handles all transforms. Symfony HttpClient widened to
support L13's Symfony 7 baseline.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Create `config/fs.php` and wire it through the service provider

**Files:**
- Create: `config/fs.php`
- Modify: `src/FilesystemServiceProvider.php`
- Create: `tests/Feature/ConfigBindingsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class ConfigBindingsTest extends TestCase
{
    public function test_default_file_model_binding_resolves_to_package_class(): void
    {
        $this->assertSame(File::class, config('fs.models.file'));
    }

    public function test_default_routes_are_enabled(): void
    {
        $this->assertTrue(config('fs.routes.enabled'));
        $this->assertSame('__fs', config('fs.routes.prefix'));
    }

    public function test_glide_config_defaults_present(): void
    {
        $this->assertNotEmpty(config('fs.glide.cache_path'));
        $this->assertSame(2000 * 2000, config('fs.glide.max_image_size'));
    }

    public function test_cloud_disks_default_to_s3_and_do(): void
    {
        $this->assertSame(['s3', 'do'], config('fs.cloud_disks'));
    }
}
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ConfigBindingsTest.php`
Expected: 4 failures. `config('fs.*')` returns `null` because the config file doesn't exist yet.

- [ ] **Step 3: Create `config/fs.php`**

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Eloquent model bindings
    |--------------------------------------------------------------------------
    |
    | If your host app extends Jiannius\Filesystem\Models\File with its own
    | App\Models\File, set `file` to that class. Leave as default to use the
    | package's model directly. `user` falls back to
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
    | Set `enabled` to false to register your own routes instead of the
    | package defaults. `prefix` controls the URI prefix for both upload
    | and image routes. `middleware` applies to both routes;
    | `upload_middleware` is appended to the upload route only.
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
    | `cache_path` is where Glide writes transformed image variants; it is
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
    | When a File row with env=production is deleted from a non-production
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
    | 0 (or any falsy non-null value) to default to non-expiring signed URLs.
    |
    */
    'image_url_default_ttl_days' => 7,

];
```

- [ ] **Step 4: Update `src/FilesystemServiceProvider.php`**

Replace the file entirely:

```php
<?php

namespace Jiannius\Filesystem;

use Illuminate\Support\ServiceProvider;

class FilesystemServiceProvider extends ServiceProvider
{
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
}
```

- [ ] **Step 5: Run the test and verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ConfigBindingsTest.php`
Expected: 4 tests pass.

- [ ] **Step 6: Re-run smoke tests to confirm no regression**

Run: `vendor/bin/phpunit`
Expected: 6 total tests pass (4 from ConfigBindings, 2 from Smoke).

- [ ] **Step 7: Commit**

```bash
git add config/fs.php src/FilesystemServiceProvider.php tests/Feature/ConfigBindingsTest.php
git commit -m "feat: add config/fs.php for model bindings, routes, glide and disks

Service provider merges the config in register() and exposes it for
publishing via the 'fs-config' tag. Routes can be disabled if the host
wants to register its own. Naming chosen as 'fs' to avoid visual
collision with Laravel's built-in config/filesystems.php.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Decouple `File::user()` from `\App\Models\User`

**Files:**
- Modify: `src/Models/File.php:106-109`
- Modify: `tests/Feature/ConfigBindingsTest.php` (add test)

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Feature/ConfigBindingsTest.php`:

```php
public function test_user_relation_uses_auth_provider_model_when_config_is_null(): void
{
    config(['auth.providers.users.model' => \stdClass::class]);

    $file = new \Jiannius\Filesystem\Models\File();
    $relation = $file->user();

    $this->assertSame(\stdClass::class, $relation->getRelated()::class);
}

public function test_user_relation_uses_fs_models_user_when_set(): void
{
    config(['fs.models.user' => \stdClass::class]);

    $file = new \Jiannius\Filesystem\Models\File();
    $relation = $file->user();

    $this->assertSame(\stdClass::class, $relation->getRelated()::class);
}
```

- [ ] **Step 2: Run the tests and verify they fail**

Run: `vendor/bin/phpunit --filter user_relation tests/Feature/ConfigBindingsTest.php`
Expected: 2 failures. Current code resolves `\App\Models\User` which doesn't exist in the test environment.

- [ ] **Step 3: Update `File::user()` in `src/Models/File.php`**

Replace the existing method:

```php
public function user() : BelongsTo
{
    $model = config('fs.models.user') ?? config('auth.providers.users.model');

    return $this->belongsTo($model);
}
```

- [ ] **Step 4: Run the tests and verify they pass**

Run: `vendor/bin/phpunit --filter user_relation tests/Feature/ConfigBindingsTest.php`
Expected: 2 tests pass.

- [ ] **Step 5: Run full suite**

Run: `vendor/bin/phpunit`
Expected: 8 total tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Models/File.php tests/Feature/ConfigBindingsTest.php
git commit -m "refactor(model): resolve user relation via config, drop App\\Models\\User ref

Falls back to config('auth.providers.users.model') when fs.models.user
is null. Removes a hard reference to a host-app class so the package
works without scaffolding on a fresh Laravel install.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Decouple controllers from `App\Models\File` and `App\Http\Controllers\Controller`

**Files:**
- Modify: `src/Controllers/UploadController.php`
- Modify: `src/Controllers/ImageController.php`
- Create: `tests/Feature/UploadTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class UploadTest extends TestCase
{
    public function test_upload_writes_file_to_disk_and_creates_db_row(): void
    {
        Storage::fake('local');
        // bypass the 'auth' middleware on the upload route for this test
        $this->withoutMiddleware();

        $upload = UploadedFile::fake()->image('photo.jpg', 800, 600);

        $response = $this->postJson(route('__fs.upload'), [
            'file' => $upload,
        ]);

        $response->assertOk();
        $this->assertSame(1, File::query()->count());

        $file = File::query()->firstOrFail();
        $this->assertSame('photo.jpg', $file->name);
        $this->assertStringStartsWith('image/', $file->mime);
        Storage::disk('local')->assertExists($file->path);
    }

    public function test_upload_route_uses_configured_file_model_class(): void
    {
        Storage::fake('local');
        $this->withoutMiddleware();

        config(['fs.models.file' => TestFileSubclass::class]);
        TestFileSubclass::$wasCalled = false;

        $this->postJson(route('__fs.upload'), [
            'file' => UploadedFile::fake()->create('doc.pdf', 10),
        ])->assertOk();

        $this->assertTrue(TestFileSubclass::$wasCalled, 'Configured file class was not used by controller');
    }
}

// Defined at bottom of file so it's bootable from the autoloader during the test run.
class TestFileSubclass extends File
{
    public static bool $wasCalled = false;

    public static function storeUpload($upload, $folder = '', $visibility = 'public')
    {
        static::$wasCalled = true;
        return parent::storeUpload($upload, $folder, $visibility);
    }
}
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `vendor/bin/phpunit tests/Feature/UploadTest.php`
Expected: both tests fail. The first because `App\Models\File` doesn't exist and the controller imports it; the second because the config binding isn't wired through yet.

- [ ] **Step 3: Replace `src/Controllers/UploadController.php`**

```php
<?php

namespace Jiannius\Filesystem\Controllers;

class UploadController
{
    public function __invoke()
    {
        return response()->json(
            $this->saveUrls()
            ?? $this->saveUploads()
        );
    }

    protected function saveUrls()
    {
        $urls = request()->url;

        if (!$urls) return;

        $fileClass = config('fs.models.file');

        return collect($urls)
            ->filter()
            ->map(fn ($url) => $fileClass::store($url))
            ->values()
            ->all();
    }

    protected function saveUploads()
    {
        $upload = request()->file;
        $settings = request()->settings ?? [];

        $fileClass = config('fs.models.file');
        $file = $fileClass::store($upload, $settings);

        return $file->toArray();
    }
}
```

Note: parent `Controller` extension dropped — Laravel 11+ doesn't scaffold one and neither method on the original used base-controller features.

- [ ] **Step 4: Replace `src/Controllers/ImageController.php`**

```php
<?php

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

- [ ] **Step 5: Run UploadTest and verify both pass**

Run: `vendor/bin/phpunit tests/Feature/UploadTest.php`
Expected: 2 tests pass.

- [ ] **Step 6: Run full suite**

Run: `vendor/bin/phpunit`
Expected: 10 total tests pass.

- [ ] **Step 7: Commit**

```bash
git add src/Controllers/ tests/Feature/UploadTest.php
git commit -m "refactor(controllers): drop App\\* coupling, resolve File via config

UploadController and ImageController no longer import App\\Models\\File
or extend App\\Http\\Controllers\\Controller (the latter was removed in
Laravel 11 scaffolding). Host apps that subclass the File model now
register their class via config('fs.models.file').

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Config-drive `routes/web.php`

**Files:**
- Modify: `routes/web.php`
- Modify: `tests/Feature/SmokeTest.php` (add prefix test)

- [ ] **Step 1: Write the regression test**

Add to `tests/Feature/SmokeTest.php`:

```php
public function test_route_uses_default_prefix_and_middleware(): void
{
    $upload = \Illuminate\Support\Facades\Route::getRoutes()->getByName('__fs.upload');
    $this->assertSame('__fs/upload', $upload->uri());
    $this->assertContains('web', $upload->middleware());
    $this->assertContains('auth', $upload->middleware());

    $image = \Illuminate\Support\Facades\Route::getRoutes()->getByName('__fs.image');
    $this->assertSame('__fs/img/{path}', $image->uri());
    $this->assertContains('signed', $image->middleware());
}
```

(Testing a runtime config change to the prefix is unreliable because routes are loaded in the provider's `boot()` before tests start. The regression test above locks in the default behavior; we trust the implementation that reads from config because the test in Task 3 already verified config values resolve correctly.)

- [ ] **Step 2: Run the test and verify it passes against the current code**

Run: `vendor/bin/phpunit tests/Feature/SmokeTest.php`
Expected: passes. This locks in current behavior so the next step's refactor doesn't drift.

- [ ] **Step 3: Replace `routes/web.php`**

```php
<?php

use Illuminate\Support\Facades\Route;
use Jiannius\Filesystem\Controllers\ImageController;
use Jiannius\Filesystem\Controllers\UploadController;

$prefix = config('fs.routes.prefix', '__fs');
$baseMiddleware = config('fs.routes.middleware', ['web']);
$uploadMiddleware = array_merge($baseMiddleware, config('fs.routes.upload_middleware', ['auth']));

Route::post($prefix.'/upload', UploadController::class)
    ->middleware($uploadMiddleware)
    ->name('__fs.upload');

Route::get($prefix.'/img/{path}', ImageController::class)
    ->middleware(array_merge($baseMiddleware, ['signed']))
    ->where('path', '.*')
    ->name('__fs.image');
```

Route *names* are kept as `__fs.upload` / `__fs.image` for BC with hosts that call `route('__fs.image', ...)`.

- [ ] **Step 4: Run full suite**

Run: `vendor/bin/phpunit`
Expected: all tests still pass.

- [ ] **Step 5: Commit**

```bash
git add routes/web.php tests/Feature/SmokeTest.php
git commit -m "refactor(routes): read prefix and middleware from config('fs.routes.*')

Route names remain __fs.upload and __fs.image for BC. Hosts that want
to disable the package's routes entirely can set fs.routes.enabled to
false; the service provider skips the route file in that case.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Config-drive `getGlideServer()`, `preventProductionDelete()`, `getImageUrl()`

**Files:**
- Modify: `src/Models/File.php` (3 methods)
- Create: `tests/Feature/ProductionGuardTest.php`
- Create: `tests/Feature/ImageRouteTest.php`

- [ ] **Step 1: Write the production-guard test**

```php
<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class ProductionGuardTest extends TestCase
{
    public function test_deleting_production_s3_file_in_non_prod_throws(): void
    {
        // 'do' is in the default cloud_disks list, but the file has no real path.
        $file = File::create([
            'name' => 'prod.jpg',
            'mime' => 'image/jpeg',
            'disk' => 's3',
            'path' => 'fake/prod.jpg',
            'env'  => 'production',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Do not delete production file/');

        $file->delete();
    }

    public function test_deleting_production_local_file_in_non_prod_does_not_throw(): void
    {
        // local disk is not in cloud_disks, so guard should not fire.
        \Illuminate\Support\Facades\Storage::fake('local');

        $file = File::create([
            'name' => 'prod.jpg',
            'mime' => 'image/jpeg',
            'disk' => 'local',
            'path' => null, // no actual file
            'env'  => 'production',
        ]);

        $file->delete(); // should not throw
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_cloud_disks_list_is_configurable(): void
    {
        config(['fs.cloud_disks' => ['custom']]);

        $file = File::create([
            'name' => 'prod.jpg',
            'mime' => 'image/jpeg',
            'disk' => 'custom',
            'path' => 'x/prod.jpg',
            'env'  => 'production',
        ]);

        $this->expectException(\Exception::class);
        $file->delete();
    }
}
```

- [ ] **Step 2: Run the test and verify some fail**

Run: `vendor/bin/phpunit tests/Feature/ProductionGuardTest.php`
Expected: at least `test_cloud_disks_list_is_configurable` fails (current code hardcodes `['do', 's3']`). The other two should pass because the current behavior already matches.

- [ ] **Step 3: Update `File::preventProductionDelete()` in `src/Models/File.php`**

Replace the existing method:

```php
public function preventProductionDelete(): void
{
    $cloudDisks = config('fs.cloud_disks', ['s3', 'do']);

    if (!in_array($this->disk, $cloudDisks)) return;
    if (!$this->path) return;

    throw_if(
        $this->env === 'production' && !app()->environment('production'),
        \Exception::class,
        'Do not delete production file in '.app()->environment().' environment!',
    );
}
```

- [ ] **Step 4: Run ProductionGuardTest and verify all pass**

Run: `vendor/bin/phpunit tests/Feature/ProductionGuardTest.php`
Expected: 3 tests pass.

- [ ] **Step 5: Update `File::getGlideServer()` in `src/Models/File.php`**

Replace the existing method:

```php
public function getGlideServer()
{
    return \League\Glide\ServerFactory::create([
        'source'         => $this->getDisk()->getDriver(),
        'cache'          => config('fs.glide.cache_path', storage_path('app/private/glide-cache')),
        'max_image_size' => config('fs.glide.max_image_size', 2000 * 2000),
    ]);
}
```

- [ ] **Step 6: Write the image-route test**

```php
<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class ImageRouteTest extends TestCase
{
    public function test_signed_image_url_returns_image_content(): void
    {
        Storage::fake('local');

        $upload = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $stored = $upload->storeAs('test', 'photo.jpg', 'local');

        $file = File::create([
            'name'   => 'photo.jpg',
            'mime'   => 'image/jpeg',
            'disk'   => 'local',
            'path'   => $stored,
            'width'  => 100,
            'height' => 100,
        ]);

        $signedUrl = URL::temporarySignedRoute('__fs.image', now()->addMinutes(5), [
            'path' => $file->path,
        ]);

        $response = $this->get($signedUrl);

        $response->assertOk();
        $this->assertStringContainsString('image/', $response->headers->get('content-type'));
    }

    public function test_unsigned_image_url_is_rejected(): void
    {
        $response = $this->get('/__fs/img/test/photo.jpg');
        $response->assertStatus(403);
    }

    public function test_getImageUrl_uses_configured_default_ttl(): void
    {
        config(['fs.image_url_default_ttl_days' => 14]);

        $file = File::create([
            'name' => 'x.jpg',
            'mime' => 'image/jpeg',
            'disk' => 'local',
            'path' => 'x.jpg',
        ]);

        $url = $file->getImageUrl();
        // Parse the `expires` query param; should be ~14 days from now.
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $expectedExpiry = now()->addDays(14)->timestamp;
        $this->assertEqualsWithDelta($expectedExpiry, (int) $params['expires'], 60);
    }
}
```

- [ ] **Step 7: Run the test and verify the TTL test fails**

Run: `vendor/bin/phpunit tests/Feature/ImageRouteTest.php`
Expected: the third test (`uses_configured_default_ttl`) fails because the current method hardcodes `7`. The other two may also need adjustment — see Step 9.

- [ ] **Step 8: Update `File::getImageUrl()` in `src/Models/File.php`**

Replace the existing method:

```php
public function getImageUrl(array $config = [], ?int $valid = null)
{
    if (!$this->is_image) return;

    if ($valid === null) {
        $valid = config('fs.image_url_default_ttl_days', 7);
    }

    return URL::temporarySignedRoute('__fs.image', $valid ? now()->addDays($valid) : null, [
        'path' => $this->path,
        ...$config,
    ]);
}
```

- [ ] **Step 9: Run ImageRouteTest and verify**

Run: `vendor/bin/phpunit tests/Feature/ImageRouteTest.php`
Expected: 3 tests pass. `Storage::fake('local')` returns a real Flysystem driver backed by `storage/framework/testing/disks/local`, which Glide consumes the same way it would consume any local disk.

- [ ] **Step 10: Run full suite**

Run: `vendor/bin/phpunit`
Expected: all tests pass.

- [ ] **Step 11: Commit**

```bash
git add src/Models/File.php tests/Feature/ProductionGuardTest.php tests/Feature/ImageRouteTest.php
git commit -m "refactor(model): read glide, cloud-disks, and image-url TTL from config

preventProductionDelete now reads the cloud-disks list from
fs.cloud_disks. getGlideServer reads cache_path and max_image_size
from fs.glide.*. getImageUrl falls back to fs.image_url_default_ttl_days
when no explicit TTL is passed. Public signatures preserved except
getImageUrl(\$valid) now defaults to null (resolves from config).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Replace remote HTTP calls with the `Http` facade

**Files:**
- Modify: `src/Models/File.php` (`storeYoutube`, `storeImageUrl`, `getBase64`)
- Create: `tests/Feature/YoutubeStoreTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class YoutubeStoreTest extends TestCase
{
    public function test_storeYoutube_creates_record_with_noembed_metadata(): void
    {
        Http::fake([
            'noembed.com/*' => Http::response([
                'title'         => 'Test Video Title',
                'thumbnail_url' => 'https://example.com/thumb.jpg',
            ], 200),
        ]);

        $file = File::storeYoutube('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $this->assertNotNull($file);
        $this->assertSame('Test Video Title', $file->name);
        $this->assertSame('youtube', $file->mime);
        $this->assertSame('dQw4w9WgXcQ', $file->data['vid']);
        $this->assertSame('https://example.com/thumb.jpg', $file->data['thumbnail']);
        $this->assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ', $file->data['embed']);
    }

    public function test_storeYoutube_degrades_gracefully_when_noembed_fails(): void
    {
        Http::fake([
            'noembed.com/*' => Http::response(null, 500),
        ]);

        $file = File::storeYoutube('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $this->assertNotNull($file);
        $this->assertSame('dQw4w9WgXcQ', $file->name); // falls back to vid
        $this->assertSame('youtube', $file->mime);
    }

    public function test_storeYoutube_returns_null_for_non_youtube_url(): void
    {
        $this->assertNull(File::storeYoutube('https://example.com/notvideo'));
    }
}
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `vendor/bin/phpunit tests/Feature/YoutubeStoreTest.php`
Expected: at least the first two fail. Current code uses `file_get_contents` which bypasses `Http::fake()` entirely, so it'll hit real noembed.com (or fail offline).

- [ ] **Step 3: Update `File::storeYoutube` in `src/Models/File.php`**

Replace the existing method:

```php
public static function storeYoutube(string $url)
{
    $regex = '/(?<=(?:v|i)=)[a-zA-Z0-9-]+(?=&)|(?<=(?:v|i)\/)[^&\n]+|(?<=embed\/)[^"&\n]+|(?<=(?:v|i)=)[^&\n]+|(?<=youtu.be\/)[^&\n]+/';
    preg_match($regex, $url, $matches);
    $vid = head($matches) ?? '';

    if (!$vid) return;

    $info = rescue(fn () => \Illuminate\Support\Facades\Http::timeout(5)
        ->get('https://noembed.com/embed', ['dataType' => 'json', 'url' => $url])
        ->throw()
        ->json()
    );

    $embed = 'https://www.youtube.com/embed/'.$vid;

    return self::create([
        'name' => data_get($info, 'title') ?? $vid,
        'mime' => 'youtube',
        'url'  => $url,
        'data' => [
            'vid'       => $vid,
            'thumbnail' => data_get($info, 'thumbnail_url'),
            'embed'     => $embed,
        ],
    ]);
}
```

- [ ] **Step 4: Update `File::storeImageUrl` in `src/Models/File.php`**

Replace the existing method:

```php
public static function storeImageUrl(string $url)
{
    $info = rescue(function () use ($url) {
        $body = \Illuminate\Support\Facades\Http::timeout(5)->get($url)->throw()->body();

        $tmp = tmpfile();
        $meta = stream_get_meta_data($tmp);
        file_put_contents($meta['uri'], $body);

        return getimagesize($meta['uri']);
    });

    if (!$info) return;

    return self::create([
        'name'   => $url,
        'mime'   => data_get($info, 'mime'),
        'url'    => $url,
        'width'  => data_get($info, 0),
        'height' => data_get($info, 1),
    ]);
}
```

- [ ] **Step 5: Update `File::getBase64` in `src/Models/File.php`**

Replace the existing method:

```php
public function getBase64()
{
    if (!$this->url) return;
    if (!$this->is_image) return;

    $ext = pathinfo(parse_url($this->url, PHP_URL_PATH), PATHINFO_EXTENSION);
    $content = rescue(fn () => \Illuminate\Support\Facades\Http::timeout(10)->get($this->url)->throw()->body());

    if ($content === null) return;

    return 'data:image/'.$ext.';base64,'.base64_encode($content);
}
```

- [ ] **Step 6: Run YoutubeStoreTest and verify all pass**

Run: `vendor/bin/phpunit tests/Feature/YoutubeStoreTest.php`
Expected: 3 tests pass.

- [ ] **Step 7: Run full suite**

Run: `vendor/bin/phpunit`
Expected: all tests pass.

- [ ] **Step 8: Commit**

```bash
git add src/Models/File.php tests/Feature/YoutubeStoreTest.php
git commit -m "refactor(model): use Http facade for noembed, image-url ingest, base64

Replaces file_get_contents() and remote getimagesize() with
Http::timeout(...)->throw()->json/body calls so requests have explicit
timeouts and degrade gracefully via rescue(). storeImageUrl now writes
the response to a tmpfile() and runs getimagesize() locally — gives
real timeout control instead of relying on the default_socket_timeout
ini setting.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: Add delete + Glide-cache purge test

**Files:**
- Create: `tests/Feature/DeleteCachePurgeTest.php`

This task adds regression protection for the cache-purge behavior introduced in commit `7743824`. No production code changes — just confirming the existing path still works after all the refactors.

- [ ] **Step 1: Write the test**

```php
<?php

namespace Jiannius\Filesystem\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Jiannius\Filesystem\Models\File;
use Jiannius\Filesystem\Tests\TestCase;

class DeleteCachePurgeTest extends TestCase
{
    public function test_deleting_file_removes_disk_entry(): void
    {
        Storage::fake('local');

        $stored = UploadedFile::fake()->image('img.jpg', 50, 50)
            ->storeAs('uploads', 'img.jpg', 'local');

        $file = File::create([
            'name' => 'img.jpg',
            'mime' => 'image/jpeg',
            'disk' => 'local',
            'path' => $stored,
        ]);

        Storage::disk('local')->assertExists($stored);

        $file->delete();

        Storage::disk('local')->assertMissing($stored);
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_deleting_file_without_path_is_a_noop_on_disk(): void
    {
        // e.g. YouTube records have no path; deleting them should not throw.
        $file = File::create([
            'name' => 'video',
            'mime' => 'youtube',
        ]);

        $file->delete();

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }
}
```

- [ ] **Step 2: Run and verify pass**

Run: `vendor/bin/phpunit tests/Feature/DeleteCachePurgeTest.php`
Expected: 2 tests pass.

(Note: the test asserts disk removal, not Glide-cache removal. Glide cache purge runs against a real cache dir which `Storage::fake` doesn't fully simulate. Asserting on disk is sufficient for regression protection — the cache purge is one extra line in `deleteFromDisk()` and visually reviewable.)

- [ ] **Step 3: Run full suite**

Run: `vendor/bin/phpunit`
Expected: all tests pass.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/DeleteCachePurgeTest.php
git commit -m "test: regression coverage for file delete and disk cleanup

Locks in the existing deleteFromDisk() behavior so it can't silently
regress under future refactors.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: Version bump, CHANGELOG, CLAUDE.md update

**Files:**
- Modify: `composer.json` (version)
- Create: `CHANGELOG.md`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Bump `composer.json` `version` to `1.0.0`**

Change the line `"version": "0.2.5"` to `"version": "1.0.0"`.

- [ ] **Step 2: Create `CHANGELOG.md`**

```markdown
# Changelog

All notable changes to `jiannius/filesystem`.

## 1.0.0 — 2026-05-12

### Breaking

- **Laravel 13 required.** Drops support for Laravel 12 and earlier. Hosts on Laravel 12 should stay pinned to `^0.2`.
- **PHP 8.2 minimum.**
- **Controllers no longer extend `App\Http\Controllers\Controller`.** This class was removed in Laravel 11's default scaffolding; the package now extends nothing.
- **Controllers no longer import `App\Models\File`.** They resolve the file model from `config('fs.models.file')` (default: the package's own `Jiannius\Filesystem\Models\File`).
- **`File::user()` no longer references `\App\Models\User`.** It resolves from `config('fs.models.user')` with a fallback to `config('auth.providers.users.model')`.
- **`File::getImageUrl()`** signature: `getImageUrl(array $config = [], ?int $valid = null)`. When `$valid === null` the method now reads `config('fs.image_url_default_ttl_days')` (default 7). Passing an integer `0` continues to produce a no-expiry signed URL. Hosts that previously passed `null` explicitly for no-expiry should switch to `0`.
- Dropped dependencies: `doctrine/dbal`, `intervention/image` (both were unused in the package source).

### Added

- `config/fs.php` — publishable via `php artisan vendor:publish --tag=fs-config`. Controls model bindings, route registration, Glide settings, cloud-disk guard list, and signed-URL TTL.
- Orchestra Testbench test harness in `tests/`.

### Migration guide

1. `composer require jiannius/filesystem:^1.0`
2. `php artisan vendor:publish --tag=fs-config` to publish `config/fs.php`.
3. If your app extends the model as `App\Models\File extends Jiannius\Filesystem\Models\File`, set `'models.file' => \App\Models\File::class` in the new config.
4. If your `app/Http/Controllers/Controller.php` only existed to satisfy this package, you can delete it.
5. Route names `__fs.upload` and `__fs.image` are preserved — `route('__fs.image', ['path' => ...])` continues to work.
```

- [ ] **Step 3: Update `CLAUDE.md`**

Replace the "Consumer-extension pattern (important)" section in `CLAUDE.md` with new content that reflects config-based bindings. Use Read then Edit.

Read `CLAUDE.md`, then replace the section that starts with "### Consumer-extension pattern (important)" through the line ending "...belongs-to `\App\Models\User` from the host app." with:

```markdown
### Config-driven bindings (1.0+)

The package owns its own `Jiannius\Filesystem\Models\File`. Controllers and the model itself resolve dependent classes from `config('fs.*')` rather than hardcoding host-app classes:

- `config('fs.models.file')` — defaults to the package's own `File`. Hosts that want to extend the model point this at their `App\Models\File extends Jiannius\Filesystem\Models\File`.
- `config('fs.models.user')` — null by default; falls back to `config('auth.providers.users.model')`. Controls the target of `File::user()`.
- `config('fs.routes.*')` — toggle/rename the package's two routes if you want to register your own.
- `config('fs.glide.*')` — Glide cache path and max image size.
- `config('fs.cloud_disks')` — disks subject to the production-delete guard.

Pre-1.0 versions (`0.2.x` and earlier) hardcoded `App\Models\File`, `App\Models\User`, and `App\Http\Controllers\Controller` references inside the package. Those required the host app to scaffold matching classes. The 1.0 config pattern replaces all of that.
```

Also update the "Common commands" section to add the test command:

```bash
composer test                # run the Orchestra Testbench feature suite
vendor/bin/phpunit tests/Feature/UploadTest.php   # run a single test file
```

- [ ] **Step 4: Run full suite one more time**

Run: `vendor/bin/phpunit`
Expected: all tests pass on a clean run.

- [ ] **Step 5: Validate composer.json**

Run: `composer validate --strict`
Expected: valid.

- [ ] **Step 6: Commit**

```bash
git add composer.json CHANGELOG.md CLAUDE.md
git commit -m "release: prepare 1.0.0

- Bump composer version to 1.0.0.
- Add CHANGELOG.md with host-app migration notes.
- Update CLAUDE.md to document config-driven bindings.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 7: Tag and (optional) push — confirm with user before tagging**

This step requires user confirmation before running. The tag is durable history.

```bash
git tag 1.0.0
# git push origin main && git push origin 1.0.0   # only if user explicitly asks
```

---

## Verification Checklist (run before tagging 1.0.0)

After Task 10, before pushing the tag:

- [ ] `composer install` from a clean clone resolves on PHP 8.2+ with Laravel 13.
- [ ] `composer validate --strict` reports valid.
- [ ] `vendor/bin/phpunit` passes all feature tests (count ≈ 16+ across 7 test files).
- [ ] Optional: install `dev-main` in one host app, upload a file, render an image URL, fetch the image, delete the record. (Lower-priority — the test harness covers the same flows.)
- [ ] `composer.json` `version` = `1.0.0`.
- [ ] `git tag 1.0.0` created.
