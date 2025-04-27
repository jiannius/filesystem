<x-drawer class="max-w-screen-lg">
    <x-slot:heading title="{!! get($config, 'title', 'app.label.files-library') !!}"></x-slot:heading>

    <div
        x-cloak
        x-data="{
            files: @entangle('files'),
            multiple: {{Js::from(get($config, 'multiple'))}},
            checkboxes: [],

            isSelected (id) {
                return this.checkboxes.findIndex(val => (val.id === id)) > -1
            },

            select (file) {
                if (this.multiple) {
                    let index = this.checkboxes.findIndex(val => (val.id === file.id))
                    if (index > -1) this.checkboxes.splice(index, 1)
                    else this.checkboxes.push(file)
                }
                else {
                    this.$dispatch('files-selected', [file])
                    close()
                }
            },

            scrollToBottom () {
                let body = $el.closest('.drawer-body')
                body.scrollTop = body.scrollHeight
            },
        }"
        x-on:uploaded="$wire.loadFiles()"
        class="flex flex-col">
        <div x-show="checkboxes.length" class="p-4 flex items-center gap-3 border-b">
            <div class="bg-gray-100 border border-gray-300 rounded-lg py-0.5 px-3 text-gray-500 inline-flex items-center gap-2">
                <span x-text="`${checkboxes.length} selected`" class="text-sm font-medium"></span>
                <div x-on:click="checkboxes = []" class="cursor-pointer flex">
                    <x-icon name="xmark" class="m-auto"/>
                </div>
            </div>
    
            <x-button action="submit" label="app.label.select" x-on:click="() => {
                $dispatch('files-selected', checkboxes)
                close()
            }"/>
        </div>
    
        <div x-show="!checkboxes.length" class="p-4 border-b flex items-center gap-4">
            <div class="grow">
                <x-input wire:model.debounce.500="filters.search" icon="search" placeholder="app.label.search" no-label/>
            </div>

            <div class="shrink-0">
                <x-button action="file-upload" multiple/>
            </div>
        </div>

        @if ($files && count($files))
            <div class="flex flex-col divide-y">
                @foreach ($files as $file)
                    <div
                        x-on:click="select(@js($file))" 
                        x-bind:class="isSelected(@js(get($file, 'id'))) ? 'bg-slate-100' : 'hover:bg-slate-50'"
                        class="py-2 px-4 flex items-center gap-3 border-b cursor-pointer">
                        <div x-show="isSelected(@js(get($file, 'id')))" class="shrink-0 flex text-green-500 pointer-events-none">
                            <x-checkbox checked/>
                        </div>

                        <figure class="shrink-0 w-10 h-10 rounded-md border overflow-hidden flex text-gray-400">
                            @if (get($file, 'is_image'))
                                <img src="{{ get($file, 'endpoint_sm') }}" class="w-full h-full object-cover">
                            @else
                                <x-icon name="file" class="m-auto"/>
                            @endif
                        </figure>

                        <div class="grow grid px-2">
                            <div class="font-medium truncate">{{ get($file, 'name') }}</div>
                            <div class="text-sm font-medium text-gray-500 md:hidden">
                                {{ collect([get($file, 'type'), get($file, 'size')])->filter()->join(' | ') }}
                            </div>
                        </div>

                        <div class="shrink-0 truncate px-2 w-40 text-right text-gray-500 hidden md:block">
                            {{ get($file, 'filesize') }}
                        </div>
                        <div class="shrink-0 truncate px-2 w-40 text-right text-gray-500 hidden md:block">
                            {{ get($file, 'mime') }}
                        </div>
                    </div>                
                @endforeach

                @if ($hasMorePages)
                    <x-anchor label="app.label.load-more" align="center" class="py-2"
                        x-on:click="$wire.loadMore().then(() => $nextTick(() => scrollToBottom()))">
                    </x-anchor>
                @endif
            </div>
        @else
            <x-no-result sm/>
        @endif
    </div>
</x-drawer>
