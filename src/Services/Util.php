<?php

namespace Jiannius\Filesystem\Services;

class Util
{
    public static function isAtomInstalled() : bool
    {
        return \Composer\InstalledVersions::isInstalled('jiannius/atom-livewire');
    }

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

    public static function icon($name) : string
    {
        return match ($name) {
            'upload' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-upload-icon lucide-upload"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>',
            'loader' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-loader-icon lucide-loader"><path d="M12 2v4"/><path d="m16.2 7.8 2.9-2.9"/><path d="M18 12h4"/><path d="m16.2 16.2 2.9 2.9"/><path d="M12 18v4"/><path d="m4.9 19.1 2.9-2.9"/><path d="M2 12h4"/><path d="m4.9 4.9 2.9 2.9"/></svg>',
            'image' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-image-icon lucide-image"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>',
            'video' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-video-icon lucide-video"><path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg>',
            'audio' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-music-icon lucide-music"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
            'word' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M17 19H20V4.99997H17V2.99997H21C21.5523 2.99997 22 3.44769 22 3.99997V20C22 20.5523 21.5523 21 21 21H17V19ZM2.85858 2.87732L15.4293 1.0815C15.7027 1.04245 15.9559 1.2324 15.995 1.50577C15.9983 1.52919 16 1.55282 16 1.57648V22.4235C16 22.6996 15.7761 22.9235 15.5 22.9235C15.4763 22.9235 15.4527 22.9218 15.4293 22.9184L2.85858 21.1226C2.36593 21.0522 2 20.6303 2 20.1327V3.86727C2 3.36962 2.36593 2.9477 2.85858 2.87732ZM4 4.73457V19.2654L14 20.694V3.30599L4 4.73457ZM11 7.99997H13V16H11L9 14L7 16H5V7.99997H7L7.01083 13L9 11L11 12.989V7.99997Z"></path></svg>',
            'excel' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M2.85858 2.87732L15.4293 1.0815C15.7027 1.04245 15.9559 1.2324 15.995 1.50577C15.9983 1.52919 16 1.55282 16 1.57648V22.4235C16 22.6996 15.7761 22.9235 15.5 22.9235C15.4763 22.9235 15.4527 22.9218 15.4293 22.9184L2.85858 21.1226C2.36593 21.0522 2 20.6303 2 20.1327V3.86727C2 3.36962 2.36593 2.9477 2.85858 2.87732ZM4 4.73457V19.2654L14 20.694V3.30599L4 4.73457ZM17 19H20V4.99997H17V2.99997H21C21.5523 2.99997 22 3.44769 22 3.99997V20C22 20.5523 21.5523 21 21 21H17V19ZM10.2 12L13 16H10.6L9 13.7143L7.39999 16H5L7.8 12L5 7.99997H7.39999L9 10.2857L10.6 7.99997H13L10.2 12Z"></path></svg>',
            'ppt' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M2.85858 2.87732L15.4293 1.0815C15.7027 1.04245 15.9559 1.2324 15.995 1.50577C15.9983 1.52919 16 1.55282 16 1.57648V22.4235C16 22.6996 15.7761 22.9235 15.5 22.9235C15.4763 22.9235 15.4527 22.9218 15.4293 22.9184L2.85858 21.1226C2.36593 21.0522 2 20.6303 2 20.1327V3.86727C2 3.36962 2.36593 2.9477 2.85858 2.87732ZM4 4.73457V19.2654L14 20.694V3.30599L4 4.73457ZM17 19H20V4.99997H17V2.99997H21C21.5523 2.99997 22 3.44769 22 3.99997V20C22 20.5523 21.5523 21 21 21H17V19ZM5 7.99997H13V14H7V16H5V7.99997ZM7 9.99998V12H11V9.99998H7Z"></path></svg>',
            'pdf' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M5 4H15V8H19V20H5V4ZM3.9985 2C3.44749 2 3 2.44405 3 2.9918V21.0082C3 21.5447 3.44476 22 3.9934 22H20.0066C20.5551 22 21 21.5489 21 20.9925L20.9997 7L16 2H3.9985ZM10.4999 7.5C10.4999 9.07749 10.0442 10.9373 9.27493 12.6534C8.50287 14.3757 7.46143 15.8502 6.37524 16.7191L7.55464 18.3321C10.4821 16.3804 13.7233 15.0421 16.8585 15.49L17.3162 13.5513C14.6435 12.6604 12.4999 9.98994 12.4999 7.5H10.4999ZM11.0999 13.4716C11.3673 12.8752 11.6042 12.2563 11.8037 11.6285C12.2753 12.3531 12.8553 13.0182 13.5101 13.5953C12.5283 13.7711 11.5665 14.0596 10.6352 14.4276C10.7999 14.1143 10.9551 13.7948 11.0999 13.4716Z"></path></svg>',
            'delete' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trash2-icon lucide-trash-2"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>',
            default => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-file-text-icon lucide-file-text"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>',
        };
    }
}
