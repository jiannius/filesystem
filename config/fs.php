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
