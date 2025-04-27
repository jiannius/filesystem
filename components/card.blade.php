@php
$file = $attributes->get('file');
$variant = $attributes->get('variant', 'inline');
$removeable = $attributes->has('wire:remove') || $attributes->has('x-on:remove');

$name = get($file, 'name');
$src = get($file, 'endpoint');
$srcsm = get($file, 'endpoint_sm');
$type = get($file, 'is_image') ? 'image' : get($file, 'type');
$icon = get($file, 'icon') ?? 'file';

$attrs = $attributes->except(['file', 'variant']);
@endphp

@if ($variant === 'card')
    <div {{ $attrs }}>
        <div class="group w-full relative pt-[100%]">
            <div class="absolute inset-0 bg-gray-100 rounded-md overflow-hidden shadow flex items-center justify-center">
                @if ($type === 'image') <img src="{{ $src }}" class="w-full h-full object-cover">
                @else <atom:icon :name="$icon" size="50%" class="text-muted-more"/>
                @endif
            </div>

            @if ($removeable)
                <div
                    x-on:click.stop="$dispatch('remove')"
                    class="absolute -top-2 -right-2 w-5 h-5 border border-zinc-200 bg-white shadow-sm flex items-center justify-center rounded-md text-red-500 opacity-0 cursor-pointer group-hover:opacity-100">
                    <atom:icon close size="14"/>
                </div>
            @endif
        </div>
    </div>
@elseif ($variant === 'inline')
    <div {{ $attrs->class(['flex gap-3']) }}>
        <div class="shrink-0 w-12 h-12 rounded-lg bg-zinc-100 border shadow-sm flex items-center justify-center overflow-hidden">
            @if ($type === 'image')
                <object data="{{ $srcsm }}" class="w-full h-full object-cover">
                    <img src="{{ $src }}" class="w-full h-full object-cover">
                </object>
            @else
                <atom:icon :name="$icon" class="text-muted"/>
            @endif
        </div>

        <div class="grow grid">
            <div class="font-medium truncate">@ee($name)</div>
            <div class="text-sm text-muted">@e($type)</div>
        </div>

        @if ($removeable)
            <div
            x-on:click.stop="$dispatch('remove')"
            class="shrink-0 text-muted-more flex justify-center cursor-pointer">
                <atom:icon delete/>
            </div>
        @endif
    </div>
@endif
