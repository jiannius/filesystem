<?php

namespace Jiannius\Filesystem\Controllers;

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
        $cachedPath = $server->makeImage($path, request()->except(['expires', 'signature']));
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
            'Cache-Control'  => 'max-age=31536000, public',
            'Expires'        => date_create('+1 years')->format('D, d M Y H:i:s').' GMT',
        ]);
    }
}
