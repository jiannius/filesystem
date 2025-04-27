<?php

namespace Jiannius\Filesystem\Livewire;

use Jiannius\Atom\Traits\Livewire\AtomComponent;
use Livewire\Component;

class Picker extends Component
{
    use AtomComponent;

    public $page = 1;
    public $files;
    public $hasMorePages;

    public $filters = [
        'search' => null,
    ];

    public $config = [
        'accept' => '*',
        'multiple' => false,
    ];

    protected $listeners = [
        'showFilesLibrary' => 'open',
    ];

    // updated page
    public function updatedPage() : void
    {
        $this->loadFiles();
    }

    // updated filters
    public function updatedFilters() : void
    {
        $this->page = 1;
        $this->loadFiles();
    }

    // open
    public function open($config = []) : void
    {
        $this->config = [...$this->config, ...$config];
        $this->loadFiles();
        $this->overlay();
    }

    // load files
    public function loadFiles() : void
    {
        $paginator = model('file')
        ->whereNull('parent_id')
        ->filter($this->filters)
        ->latest()
        ->toPage($this->page, 50);

        if ($this->page === 1) $this->files = collect($paginator->items());
        else $this->files = collect($this->files)->concat($paginator->items());

        $this->hasMorePages = $paginator->hasMorePages();
    }

    // load more
    public function loadMore() : void
    {
        $this->page++;
        $this->loadFiles();
    }

    public function render()
    {
        return view('filesystem::livewire.picker');
    }
}