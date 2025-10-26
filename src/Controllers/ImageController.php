<?php

namespace Jiannius\Filesystem\Controllers;

use App\Models\File;
use App\Http\Controllers\Controller;
use League\Glide\ServerFactory;

/**
 * Using league/glide to alter image on the fly using the URL parameters
 */
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

        $server = ServerFactory::create([
            'source' => $file->getDisk()->getDriver(),
            'cache' => storage_path('app/glide-cache'),
            'max_image_size' => 2000*2000,
        ]);

        $server->outputImage($path, request()->except(['expires', 'signature']));
    }
}
