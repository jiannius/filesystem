<?php

namespace Jiannius\Filesystem\Livewire;

use App\Models\File;
use Jiannius\Atom\Traits\Livewire\AtomComponent;
use Jiannius\Filesystem\Services\Util;
use Livewire\Component;

class Manager extends Component
{
    use AtomComponent;

    public $filters = [];

    protected $listeners = [
        'uploaded' => '$refresh',
        'updated-file' => '$refresh',
        'deleted-file' => '$refresh',
    ];

    public function mount()
    {
        $this->filters = [
            'mime' => null,
            'search' => null,
            ...$this->filters,
        ];
    }

    public function getStorageProperty() : string
    {
        return Util::filesize(File::sum('kb'));
    }

    public function getFilesProperty()
    {
        return $this->getTable(
            query: File::query()->filter($this->filters),
        );
    }

    public function delete() : void
    {
        if ($id = get($this->table, 'checkboxes')) {
            model('file')->whereIn('id', $id)->get()->each(fn($file) => $file->delete());
            $this->fill(['table.checkboxes' => []]);
        }
    }

    public function render()
    {
        return view('filesystem::livewire.manager');
    }
}
