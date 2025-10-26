<?php

use Illuminate\Support\Facades\Route;

Route::post('__fs/upload', \Jiannius\Filesystem\Controllers\UploadController::class)
    ->middleware(['web', 'auth'])
    ->name('__fs.upload');

Route::get('__fs/img/{path}', \Jiannius\Filesystem\Controllers\ImageController::class)
    ->middleware(['web', 'signed'])
    ->where('path', '.*')
    ->name('__fs.image');