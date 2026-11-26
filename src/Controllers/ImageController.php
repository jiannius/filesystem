<?php

namespace Jiannius\Filesystem\Controllers;

use Intervention\Image\Exceptions\DecoderException;
use Intervention\Image\Exceptions\NotSupportedException;
use League\Glide\Filesystem\FileNotFoundException;
use Symfony\Component\HttpFoundation\Response;
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

        $params = request()->except(['expires', 'signature']);

        // Conditional request: answer 304 before any Glide work or blob read.
        $probe = $this->withCacheHeaders(new Response(), $file, $path, $params);

        if ($probe->isNotModified(request())) return $probe;

        $server = $file->getGlideServer();

        try {
            $cachedPath = $server->makeImage($path, $params);
        } catch (FileNotFoundException) {
            // The row is here but the blob is not — deleted out from under it, or never
            // copied into this environment. Glide throws, and letting that bubble made every
            // thumbnail a 500 plus a logged exception, so a listing of such rows produced a
            // burst of them. The file is genuinely absent, which is what 404 means.
            abort(404);
        } catch (DecoderException|NotSupportedException) {
            // The blob is here but the configured Glide driver cannot read (or write) its
            // format — GD cannot decode HEIC/HEIF, JPEG 2000, TIFF or SVG. Letting that bubble
            // made every thumbnail of such a file a 500 plus a logged exception. The request
            // is fine and the media is real, we just cannot transform it, so 415. It aborts
            // before the cache headers are applied, so the error is never marked cacheable.
            abort(415);
        }

        $cache = $server->getCache();

        $response = new StreamedResponse(function () use ($cache, $cachedPath) {
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

        return $this->withCacheHeaders($response, $file, $path, $params);
    }

    /**
     * Make the response browser-cacheable, for the browser only.
     *
     * The route is gated by $file->auth(), so the response is `private` — never stored by
     * shared caches (an earlier public, year-long header was dropped for exactly that, and
     * for outliving the signed URL). max-age stops at the URL's own `expires`, so a cached
     * copy never outlives the signature. Headers go through the Response API rather than
     * a raw array so Symfony's default `no-cache` does not clobber them on a stream.
     */
    protected function withCacheHeaders(Response $response, $file, string $path, array $params): Response
    {
        $expires = request()->query('expires');
        $maxAge = is_numeric($expires) ? max(0, (int) $expires - now()->timestamp) : 7 * 86400;

        $updatedAt = $file->updated_at;

        ksort($params);

        $response->setPrivate();
        $response->setMaxAge($maxAge);
        $response->setEtag(md5(implode('|', [$path, http_build_query($params), $updatedAt?->timestamp ?? 0])));

        if ($updatedAt) $response->setLastModified($updatedAt);

        return $response;
    }
}
