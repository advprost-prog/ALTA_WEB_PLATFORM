<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestStorage extends Command
{
    protected $signature = 'alta:test-storage';

    protected $description = 'Verify that the public disk can write files and expose them through public/storage.';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $path = 'products/storage-test.txt';
        $contents = 'Alta-Trade storage test '.now()->toDateTimeString();

        $this->line('filesystems.default: '.config('filesystems.default'));
        $this->line('public.root: '.config('filesystems.disks.public.root'));
        $this->line('public.url: '.config('filesystems.disks.public.url'));
        $this->line('public.visibility: '.config('filesystems.disks.public.visibility'));

        $written = $disk->put($path, $contents);
        $storageExists = $disk->exists($path);
        $url = $disk->url($path);
        $publicExists = file_exists(public_path('storage/'.$path));

        $this->newLine();
        $this->line('write products/storage-test.txt: '.($written ? 'yes' : 'no'));
        $this->line('Storage::disk(public)->exists: '.($storageExists ? 'yes' : 'no'));
        $this->line('Storage::disk(public)->url: '.$url);
        $this->line('public/storage file_exists: '.($publicExists ? 'yes' : 'no'));

        $passed = $written && $storageExists && $publicExists;

        $this->newLine();
        $passed ? $this->info('PASS') : $this->error('FAIL');

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
