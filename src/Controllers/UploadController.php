<?php

namespace Jiannius\Filesystem\Controllers;

class UploadController
{
    public function __invoke()
    {
        return response()->json(
            $this->saveUrls()
            ?? $this->saveUploads()
        );
    }

    protected function saveUrls()
    {
        $urls = request()->url;

        if (!$urls) return;

        $fileClass = config('fs.models.file');

        return collect($urls)
            ->filter()
            ->map(fn ($url) => $fileClass::store(url: $url))
            ->values()
            ->all();
    }

    protected function saveUploads()
    {
        $upload = request()->file;
        $settings = request()->settings ?? [];

        $fileClass = config('fs.models.file');
        $folder = data_get($settings, 'folder', '');
        $visibility = data_get($settings, 'visibility', 'public');

        $file = $fileClass::storeUpload($upload, $folder, $visibility);

        return $file->toArray();
    }
}
