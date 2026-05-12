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
