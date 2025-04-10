<?php

namespace Jiannius\Filesystem\Commands;

use App\Models\File;
use Illuminate\Console\Command;

class OptimizeCommand extends Command
{
    protected $signature = 'filesystem:optimize';
    protected $description = 'Optimize files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        File::query()
            ->where('mime', 'like', 'image/%')
            ->whereRaw('is_resized is null or is_resized = false')
            ->whereRaw('is_converted_to_webp is null or is_converted_to_webp = false')
            ->orderBy('id')
            ->get()
            ->each(function ($file) {
                $this->line('Optimizing '.$file->path.'...');
                $file->optimize();
            });

        $this->newLine();
        $this->info('Done!');
    }
}