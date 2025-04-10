<?php

namespace Jiannius\Filesystem\Services;

class Optimizer
{
    public $settings;

    public function __construct(public $url)
    {
        //
    }

    public function settings($settings = [])
    {
        $info = getimagesize($this->url);
        $mime = data_get($info, 'mime');
        $extension = $mime ? array_search($mime, \Jiannius\Filesystem\Models\File::MIME) : null;
        $filename = str()->random(30);

        $this->settings = [
            'mime' => str($mime)->is('image/*') ? $mime : null,
            'extension' => $extension,
            'resized_output_path' => storage_path('app/image-optimization/'.$filename.'--resized.'.$extension),
            'webp_output_path' => storage_path('app/image-optimization/'.$filename.'--webp.webp'),
            'width' => 1280,
            'height' => 1280,
            'quality' => 80,
            'webp' => true,
            ...$settings,
        ];

        return $this;
    }

    public function optimize()
    {
        throw_if(!data_get($this->settings, 'mime'), new \Exception('Image format not supported for optimization.'));

        $this->createFolders();
        $this->resize();
        $this->convertToWebp();

        $resized = data_get($this->settings, 'resized_output_path');
        $webp = data_get($this->settings, 'webp_output_path');

        if (file_exists($webp)) {
            if (file_exists($resized)) unlink($resized);
            return $webp;
        }
        else if (file_exists($resized)) {
            return $resized;
        }

        return false;
    }

    public function createFolders()
    {
        $folders = collect([
            data_get($this->settings, 'resized_output_path'),
            data_get($this->settings, 'webp_output_path'),
        ]);

        $folders
            ->map(function ($path) {
                $splits = collect(explode('/', $path));
                $splits->pop();
                return $splits->join('/');
            })
            ->filter(fn ($path) => !file_exists($path))
            ->unique()
            ->values()
            ->each(fn ($folder) => mkdir($folder, 0755));
    }

    public function resize()
    {
        $width = data_get($this->settings, 'width');
        $height = data_get($this->settings, 'height');
        $output = data_get($this->settings, 'resized_output_path');

        app('image')
            ->read(file_get_contents($this->url))
            ->scaleDown($width, $height)
            ->save($output);
    }

    public function convertToWebp()
    {
        $path = data_get($this->settings, 'resized_output_path');
        $mime = data_get($this->settings, 'mime');

        if (!file_exists($path)) return;
        if (!data_get($this->settings, 'webp')) return;
        if (!in_array($mime, ['image/jpeg', 'image/gif', 'image/png'])) return;

        $image = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/gif' => imagecreatefromgif($path),
            'image/png' => @imagecreatefrompng($path),
        };

        $alpha = $mime !== 'image/jpeg';
        $quality = data_get($this->settings, 'quality');
        $output = data_get($this->settings, 'webp_output_path');

        if ($alpha) {
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
        }

        imagewebp($image, $output, $quality);
    }
}