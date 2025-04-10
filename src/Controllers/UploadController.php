<?php

namespace Jiannius\Filesystem\Controllers;

use App\Http\Controllers\Controller;
use App\Models\File;

class UploadController extends Controller
{
    public function __invoke()
    {
        return response()->json(
            $this->saveUrls()
            ?? $this->saveUploads()
        );
    }

    public function saveUrls()
    {
        $urls = request()->url;

        if (!$urls) return;

        return collect($urls)
            ->filter()
            ->map(fn ($url) => app(File::class)->store($url))
            ->values()
            ->all();
    }

    public function saveUploads()
    {
        $upload = request()->file;
        $settings = request()->settings ?? [];

        $file = app(File::class)->store($upload, $settings);

        return $file->toArray();
    }
}
