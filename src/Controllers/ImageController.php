<?php

namespace Jiannius\Filesystem\Controllers;

use App\Models\File;
use App\Http\Controllers\Controller;

class ImageController extends Controller
{
    public function __invoke()
    {
        $path = request()->path;

        $file = File::query()
            ->withMime('image/*')
            ->where('path', $path)
            ->firstOrFail();

        if (!$file->auth()) return abort(403);

        return $file->getGlideServer()->outputImage($path, request()->except(['expires', 'signature']));
    }
}
