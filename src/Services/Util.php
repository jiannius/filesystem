<?php

namespace Jiannius\Filesystem\Services;

class Util
{
    // $upload->extension() will sometimes get .bin
    // because laravel will auto detect the extension by examining the content mime
    // to avoid getting .bin, we extract the extension from the file name
    public static function getUploadedFileExtension($upload) : string
    {
        return last(explode('.', str($upload->getClientOriginalName()))) ?? $upload->extension();
    }

    public static function getYoutubeVideoId($value) : string
    {
        $regex = '/(?<=(?:v|i)=)[a-zA-Z0-9-]+(?=&)|(?<=(?:v|i)\/)[^&\n]+|(?<=embed\/)[^"&\n]+|(?<=(?:v|i)=)[^&\n]+|(?<=youtu.be\/)[^&\n]+/';

        preg_match($regex, $value, $matches);

        return collect($matches)->first() ?? '';
    }

    public static function getYoutubeEmbedUrl($value) : string
    {
        $vid = self::getYoutubeVideoId($value);

        return 'https://www.youtube.com/embed/'.$vid;
    }

    public static function getYoutubeVideoInfo($value) : array
    {
        $vid = self::getYoutubeVideoId($value);
        $info = json_decode(file_get_contents('https://noembed.com/embed?dataType=json&url='.$value), true);

        return [
            ...$info,
            'embed_url' => $vid ? 'https://www.youtube.com/embed/'.$vid : null,
        ];
    }

    public static function filesize($value = 0, $unit = 'KB') : string
    {
        if (!is_numeric($value)) return $value;

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = array_search($unit, $units);

        while ($value > 1024) {
            $value = $value/1024;
            $index = $index + 1;
        }

        return round($value, 2).' '.$units[$index];
    }
}
