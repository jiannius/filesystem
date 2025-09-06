@use('Jiannius\Filesystem\Services\Util')

@props([
    'file' => null,
    'inline' => false,
])

@php
$name = data_get($file, 'name');
$src = data_get($file, 'endpoint');
$optimized = data_get($file, 'endpoint_o');
$type = data_get($file, 'is_image') ? 'image' : data_get($file, 'type');
$removeable = $attributes->has('wire:remove') || $attributes->has('x-on:remove');
@endphp

@if ($inline)
    <div {{ $attributes->class(['flex gap-3']) }}>
        <div class="shrink-0 size-10 rounded-lg bg-zinc-100 border shadow-sm flex items-center justify-center overflow-hidden">
            @if ($type === 'image')
                <object data="{{ $optimized }}" class="w-full h-full object-cover">
                    <img src="{{ $src }}" class="w-full h-full object-cover">
                </object>
            @else
                <span class="size-6 *:w-full *:h-full text-zinc-400">
                    {!! Util::icon($type) !!}
                </span>
            @endif
        </div>

        <div class="grow truncate">
            <div class="font-medium truncate leading-tight">{!! $name !!}</div>
            <div class="text-sm text-muted">{!! $type !!}</div>
        </div>

        @if ($removeable)
            <div
            x-on:click.stop="$dispatch('remove')"
            class="shrink-0 text-muted-more flex justify-center cursor-pointer px-1">
                <span class="size-4 *:w-full *:h-full">
                    {!! Util::icon('delete') !!}
                </span>
            </div>
        @endif
    </div>
@else
    <div {{ $attributes }}>
        <div class="group w-full relative pt-[100%]">
            <div class="absolute inset-0 bg-gray-100 rounded-md overflow-hidden shadow flex items-center justify-center">
                @if ($type === 'image')
                    <img src="{{ $src }}" class="w-full h-full object-cover">
                @else
                    <span class="size-[50%] *:w-full *:h-full text-zinc-400">
                        {!! Util::icon($type) !!}
                    </span>
                @endif
            </div>

            @if ($removeable)
                <div
                x-on:click.stop="$dispatch('remove')"
                class="absolute inset-0 opacity-0 group-hover:opacity-100 cursor-pointer">
                    <div class="absolute inset-0 rounded-md bg-black/80"></div>
                    <div class="absolute inset-0 flex items-center justify-center text-white">
                        <span class="size-[35%] *:w-full *:h-full text-white">
                            {!! Util::icon('delete') !!}
                        </span>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endif
