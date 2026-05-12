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
