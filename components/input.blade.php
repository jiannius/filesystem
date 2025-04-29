@use('Jiannius\Filesystem\Services\Util')

@php
$label = $attributes->get('label');
$caption = $attributes->get('caption');
$field = $attributes->get('field') ?? $attributes->wire('model')->value();
$required = $attributes->get('required') ?? $this->form['required'][$field] ?? false;
$error = $attributes->get('error') ?? $this->errors[$field] ?? null;
$invalid = $attributes->get('invalid', false);
$picker = $attributes->get('picker', false);

$icon = '<span class="size-4 *:w-full *:h-full">'.Util::icon('upload').'</span>';
$loader = '<span class="size-4 *:w-full *:h-full animate-spin">'.Util::icon('loader').'</span>';

$classes = $attributes->classes()
    ->add('border border-zinc-200 border-b-zinc-300/80 rounded-lg shadow-sm bg-zinc-100')
    ->add('focus:outline-none focus:border-primary group-focus/input:border-primary hover:border-primary-300')
    ->add($invalid ? 'border-red-400' : 'group-has-[[data-atom-error]]/field:border-red-400')
    ;

$attrs = $attributes->class($classes);
@endphp

@if (Util::isAtomInstalled() && ($label || $caption))
    <atom:_input.field
    :label="$label"
    :caption="$caption"
    :required="$required"
    :error="$error">
        <filesystem:input :attributes="$attributes->except(['label', 'caption', 'error'])">
            {{ $slot }}
        </filesystem:input>
    </atom:_input.field>
@else
    <filesystem:uploader tabindex="0" :attributes="$attrs">
        @if ($slot->isNotEmpty())
            <div class="bg-white p-1 rounded-t-lg border-b border-zinc-200">
                {{ $slot }}
            </div>
        @endif

        <div x-on:click="openFileInput()" class="group p-4 rounded relative cursor-pointer">
            @if ($picker)
                <div class="flex flex-col gap-2">
                    <div class="flex items-center gap-3 font-medium">
                        <div class="underline decoration-dotted flex items-center gap-2">
                            {!! $icon !!} {{ __('filesystem::messages.browse-device') }}
                        </div>
                        <span class="text-muted-more"> / </span>
                        <div x-on:click.stop="Atom.modal('filesystem.picker').slide()" class="underline decoration-dotted flex items-center gap-2 lowercase cursor-pointer">
                            {{ __('filesystem::messages.or-browse-library') }}
                        </div>
                    </div>

                    <div>
                        <span x-on:click.stop="" class="font-medium text-muted cursor-text">
                            {{ __('filesystem::messages.or-drop-paste-to-upload') }}
                        </span>
                    </div>
                </div>

                <div class="hidden group-has-[.is-loading]/uploader:flex absolute inset-0 bg-white/50 rounded-md p-3 justify-end">
                    {!! $loader !!}
                </div>
            @else
                <div class="flex flex-col gap-2">
                    <div class="underline decoration-dotted flex items-center gap-2">
                        {!! $icon !!} {{ __('filesystem::messages.browse-device') }}
                    </div>
                    <div>
                        <span x-on:click.stop="" class="font-medium text-muted cursor-text">
                            {{ __('filesystem::messages.or-drop-paste-to-upload') }}
                        </span>
                    </div>
                </div>

                <div class="hidden group-[.is-loading]/uploader:flex absolute inset-0 bg-white/50 rounded-md p-3 justify-end">
                    {!! $loader !!}
                </div>
            @endif
        </div>
    </filesystem:uploader>
@endif