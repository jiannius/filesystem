<div class="lg:max-w-screen-xl">
    <atom:_table :paginate="$this->files">
        @slot('total')
            <atom:_heading size="xl">
                @t('files-manager')
            </atom:_heading>
        @endslot

        @slot ('filters')
            <atom:_select wire:model="filters.mime" label="file-type">
                <atom:option value="*">All</atom:option>
                <atom:option value="image/*">Images</atom:option>
                <atom:option value="video/*">Videos</atom:option>
                <atom:option value="audio/*">Audios</atom:option>
                <atom:option value="youtube">Youtube</atom:option>
                <atom:option value="file">Files</atom:option>
            </atom:_select>
        @endslot

        @slot ('bar')
            <filesystem:uploader multiple>
                <button type="button" x-tooltip="t('upload-files')" x-on:click="openFileInput()">
                    <atom:icon upload/>
                </button>
            </filesystem:uploader>
        @endslot

        @slot ('actions')
            <atom:_button action="delete" size="sm" inverted>@t('delete')</atom:_button>
        @endslot

        <atom:columns>
            <atom:column checkbox/>
            <atom:column sort="name">Name</atom:column>
            <atom:column sort="size" align="right">Size</atom:column>
            <atom:column sort="created_at" align="right">Created Date</atom:column>
        </atom:columns>

        <atom:rows>
            @foreach ($this->files as $file)
                <atom:row x-on:click="Atom.modal('filesystem.edit').slide({{ js(['id' => $file->id]) }})">
                    <atom:cell :checkbox="$file->id"></atom:cell>

                    <atom:cell>
                        <filesystem:card :file="$file"/>
                    </atom:cell>

                    <atom:cell align="right">@e($file->size)</atom:cell>
                    <atom:cell align="right">@e($file->created_at->pretty())</atom:cell>
                </atom:row>
            @endforeach
        </atom:rows>

        @slot ('footer')
            <atom:subheading>@t('storage-used', ['count' => $this->storage])</atom:subheading>
        @endslot
    </atom:_table>

    <livewire:filesystem.edit :wire:key="$this->wirekey('filesystem.edit')"/>
</div>
