<?php

use Illuminate\Support\Facades\Route;

Route::post('__filesystem/upload', \Jiannius\Filesystem\Controllers\UploadController::class)
    ->middleware(['web', 'auth'])
    ->name('__filesystem.upload');
