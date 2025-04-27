<atom:modal wire:open="show">
@if ($file)
    <atom:_form>        
        <atom:_heading size="xl">@t('edit-file')</atom:_heading>

        <atom:card inset>
            <x-slot:cover class="h-80">
                <atom:embed :src="$file->endpoint" :src-sm="$file->endpoint_o"/>
            </x-slot:cover>

            @if (!$file->is_youtube)
                <div class="px-6">
                    <atom:dd label="file-type">@e($file->mime)</atom:dd>

                    @if ($size = $file->size)
                        <atom:dd label="file-size">@e($size)</atom:dd>
                    @endif

                    @if ($dim = get($file->data, 'dimension'))
                        <atom:dd label="dimension">@e($dim)</atom:dd>
                    @endif
                </div>

                <div class="p-1">
                    <atom:_button :href="$file->endpoint" icon="download" variant="ghost" block newtab>
                        @t('download')
                    </atom:_button>
                </div>
            @endif
        </atom:card>

        <atom:_input wire:model.defer="file.name" :label="$file->is_youtube ? 'Video Name' : 'File Name'"/>
            
        @if ($file->is_image)
            <atom:_input wire:model.defer="file.data.alt" label="Alt Text"/>
            <atom:_input wire:model.defer="file.data.description" label="Description"/>

            @if ($file->isVisibilityEditable())
                <atom:_select wire:model.defer="file.data.visibility" label="Visibility">
                    <atom:option value="public">Public</atom:option>
                    <atom:option value="private">Private</atom:option>
                </atom:_select>
            @endif
        @endif

        <div class="flex items-center gap-2">
            <atom:_button action="submit">@t('save')</atom:_button>
            <atom:_button action="delete" inverted>@t('delete')</atom:_button>
        </div>
    </atom:_form>
@endif
</atom:modal>