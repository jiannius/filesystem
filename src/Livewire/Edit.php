<?php

namespace Jiannius\Filesystem\Livewire;

use App\Models\File;
use Illuminate\Support\Arr;
use Jiannius\Atom\Traits\Livewire\AtomComponent;
use Livewire\Component;

class Edit extends Component
{
    use AtomComponent;

    public $file;

    protected function validation(): array
    {
        return [
            'file.name' => ['required' => 'File name is required.'],
            'file.data' => ['nullable'],
            'file.data.env' => ['nullable'],
            'file.data.alt' => ['nullable'],
            'file.data.description' => ['nullable'],
            'file.data.visibility' => ['nullable'],
        ];
    }

    public function componentName()
    {
        return 'filesystem.edit';
    }

    public function show($data = []) : void
    {
        $id = Arr::pull($data, 'id');

        $this->file = File::find($id);

        $this->file->fill([
            'data' => [
                'alt' => null,
                'description' => null,
                'visibility' => data_get($this->file->getStoreSettings(), 'visibility', 'public'),
                ...$this->file->data ?? [],
            ],
        ]);

        $this->refresh();
    }

    public function delete() : void
    {
        $this->file->delete();
        $this->event('deleted');
    }

    public function submit() : void
    {
        $this->validate();
        $this->file->save();
        $this->event('updated');
    }

    public function event($action) : void
    {
        $this->emit("{$action}-file", ['id' => $this->file->id]);
        $this->modal()->close();
    }

    public function render()
    {
        return view('filesystem::livewire.edit');
    }
}