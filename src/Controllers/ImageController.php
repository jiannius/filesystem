<?php

namespace Jiannius\Filesystem\Controllers;

use League\Glide\Filesystem\FileNotFoundException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $server = $file->getGlideServer();

        try {
            $cachedPath = $server->makeImage($path, request()->except(['expires', 'signature']));
        } catch (FileNotFoundException) {
            // The row is here but the blob is not — deleted out from under it, or never
            // copied into this environment. Glide throws, and letting that bubble made every
            // thumbnail a 500 plus a logged exception, so a listing of such rows produced a
            // burst of them. The file is genuinely absent, which is what 404 means.
            abort(404);
        }

        $cache = $server->getCache();

        return new StreamedResponse(function () use ($cache, $cachedPath) {
            $stream = $cache->readStream($cachedPath);

            if (0 !== ftell($stream)) {
                rewind($stream);
            }

            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type'   => $cache->mimeType($cachedPath),
            'Content-Length' => (string) $cache->fileSize($cachedPath),
        ]);
    }
}
