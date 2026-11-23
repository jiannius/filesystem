<?php

namespace Jiannius\Filesystem\Controllers;

use Illuminate\Http\Request;
use Jiannius\Filesystem\Support\UploadGuard;

class UploadController
{
    public function __invoke(Request $request)
    {
        $this->authorizeUpload($request);

        return response()->json(
            $this->saveUrls()
            ?? $this->saveUploads()
        );
    }

    /**
     * Run the host's optional `fs.upload.authorize` callback.
     * A return value of false aborts with a 403.
     */
    protected function authorizeUpload(Request $request): void
    {
        $authorize = config('fs.upload.authorize');

        if (!$authorize) return;

        if (is_string($authorize) && class_exists($authorize)) {
            $authorize = app($authorize);
        }

        abort_if(call_user_func($authorize, $request) === false, 403);
    }

    protected function saveUrls()
    {
        $urls = request()->url;

        if (!$urls) return;

        $fileClass = config('fs.models.file');
        $fileClass::ensureUrlFetchAllowed();

        return collect($urls)
            ->filter()
            ->map(fn ($url) => $fileClass::store(url: $url))
            ->values()
            ->all();
    }

    /**
     * Validate the uploaded file: size, extension allow-list and content check.
     * Override uploadGuard() or uploadRules() in a subclass to adjust the policy.
     */
    protected function validateUpload(): void
    {
        request()->validate($this->uploadRules());
    }

    protected function uploadRules(): array
    {
        $guard = $this->uploadGuard();

        return [
            'file' => [
                'bail',
                'required',
                'file',
                'max:'.(int) config('fs.upload.max_kb', 10240),
                function ($attribute, $value, $fail) use ($guard) {
                    if ($message = $guard->check($value)) $fail($message);
                },
            ],
        ];
    }

    protected function uploadGuard(): UploadGuard
    {
        return new UploadGuard(config('fs.upload.extensions', [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'csv', 'txt', 'xls', 'xlsx', 'doc', 'docx',
        ]));
    }

    protected function saveUploads()
    {
        $this->validateUpload();

        $upload = request()->file;

        $fileClass = config('fs.models.file');

        // Visibility and folder come from config; client-supplied settings are not used.
        $folder = (string) config('fs.upload.folder', '');
        $visibility = config('fs.upload.visibility', 'private');

        $file = $fileClass::storeUpload($upload, $folder, $visibility);

        return $file->toArray();
    }
}
