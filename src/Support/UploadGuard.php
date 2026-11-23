<?php

namespace Jiannius\Filesystem\Support;

use Illuminate\Http\UploadedFile;

/**
 * Decides whether a file received by the upload route may be stored.
 *
 * It checks the file extension against an allow-list and then looks at the
 * file's content, so a file cannot pass just by being named .jpg. The check is
 * applied by UploadController only; File::storeUpload() stays unrestricted for
 * server-side callers. Hosts can subclass this and return it from
 * UploadController::uploadGuard().
 */
class UploadGuard
{
    /** Extensions that must contain a real image. */
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'avif'];

    /** Plain-text extensions, where markup anywhere near the start is suspicious. */
    public const TEXT_EXTENSIONS = ['csv', 'txt'];

    /** How many leading bytes are inspected for binary formats. */
    public const SNIFF_BYTES = 4096;

    /** How many bytes are inspected for plain-text files (csv, txt). */
    public const TEXT_SNIFF_BYTES = 1048576;

    /** The PDF header may be preceded by up to this many bytes. */
    public const PDF_HEADER_WINDOW = 1024;

    /** Content types that are never accepted, whatever the extension. */
    public const REFUSED_MIMES = [
        'text/html',
        'application/xhtml+xml',
        'text/xml',
        'application/xml',
        'image/svg+xml',
        'text/javascript',
        'application/javascript',
        'application/x-javascript',
        'text/x-php',
        'text/php',
        'application/x-php',
        'application/x-httpd-php',
    ];

    /** Content that may not appear at the start of a file (after BOM and whitespace). */
    public const REFUSED_LEADING = [
        '<?', '<svg', '<html', '<!doctype', '<!--', '<script', '<head', '<body', '<iframe',
    ];

    /** Content that may not appear anywhere in the inspected bytes. */
    public const REFUSED_ANYWHERE = [
        '<?php', '<script', '<html', '<svg', '<!doctype html',
    ];

    /** Extra anywhere-markers applied to plain-text files only. */
    public const REFUSED_ANYWHERE_TEXT = [
        '<?xml', '<?=', '<img', '<iframe', '<object', '<embed',
    ];

    public function __construct(protected array $extensions)
    {
        $this->extensions = array_map('strtolower', $extensions);
    }

    /**
     * The lowercase extension, taken from the client file name the same way
     * File::storeUpload() does.
     */
    public static function extension(UploadedFile $upload): string
    {
        return strtolower(last(explode('.', $upload->getClientOriginalName())));
    }

    /**
     * Returns an error message when the file must be refused, or null when it is acceptable.
     */
    public function check(UploadedFile $upload): ?string
    {
        $extension = static::extension($upload);

        if (!in_array($extension, $this->extensions, true)) {
            return 'This file type is not allowed.';
        }

        $path = $upload->getRealPath();

        if (!$path || !is_readable($path)) {
            return 'The file could not be read.';
        }

        $head = $this->head($path, $extension);
        $mime = $this->mime($path);

        if ($this->looksLikeMarkupOrScript($head, $mime, $extension)) {
            return 'This file content is not allowed.';
        }

        if (in_array($extension, static::IMAGE_EXTENSIONS, true) && !$this->isImage($path, $mime)) {
            return 'The file is not a valid image.';
        }

        if ($extension === 'pdf' && !str_contains(substr($head, 0, static::PDF_HEADER_WINDOW), '%PDF-')) {
            return 'The file is not a valid PDF.';
        }

        return null;
    }

    protected function head(string $path, string $extension): string
    {
        $bytes = in_array($extension, static::TEXT_EXTENSIONS, true)
            ? static::TEXT_SNIFF_BYTES
            : static::SNIFF_BYTES;

        return (string) file_get_contents($path, false, null, 0, $bytes);
    }

    protected function mime(string $path): string
    {
        return strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));
    }

    protected function isImage(string $path, string $mime): bool
    {
        return str_starts_with($mime, 'image/')
            && $mime !== 'image/svg+xml'
            && @getimagesize($path) !== false;
    }

    protected function looksLikeMarkupOrScript(string $head, string $mime, string $extension): bool
    {
        if (in_array($mime, static::REFUSED_MIMES, true)) return true;

        // NUL bytes are dropped so they cannot be used to split a marker.
        $text = strtolower(str_replace("\0", '', $this->withoutByteOrderMark($head)));
        $leading = ltrim($text);

        foreach (static::REFUSED_LEADING as $marker) {
            if (str_starts_with($leading, $marker)) return true;
        }

        $anywhere = static::REFUSED_ANYWHERE;

        if (in_array($extension, static::TEXT_EXTENSIONS, true)) {
            $anywhere = array_merge($anywhere, static::REFUSED_ANYWHERE_TEXT);
        }

        foreach ($anywhere as $marker) {
            if (str_contains($text, $marker)) return true;
        }

        return false;
    }

    /**
     * Drop a UTF-8 BOM, or decode a UTF-16 one, so the markers can be matched as plain text.
     */
    protected function withoutByteOrderMark(string $head): string
    {
        if (str_starts_with($head, "\xEF\xBB\xBF")) return substr($head, 3);

        if (str_starts_with($head, "\xFF\xFE")) {
            return mb_convert_encoding(substr($head, 2), 'UTF-8', 'UTF-16LE');
        }

        if (str_starts_with($head, "\xFE\xFF")) {
            return mb_convert_encoding(substr($head, 2), 'UTF-8', 'UTF-16BE');
        }

        return $head;
    }
}
