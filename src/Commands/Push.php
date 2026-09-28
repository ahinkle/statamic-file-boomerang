<?php

namespace Ahinkle\FileBoomerang\Commands;

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Change;
use Ahinkle\FileBoomerang\Jobs\MailChanges;
use Ahinkle\FileBoomerang\Manifest;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Statamic\Console\RunsInPlease;

class Push extends Command
{
    use RunsInPlease;

    protected $signature = 'boomerang:push';

    protected $description = 'Mail the changes on this server to the File Boomerang mailbox';

    public function handle(): int
    {
        if (! config('file-boomerang.enabled')) {
            $this->components->warn('File Boomerang is disabled. Set FILE_BOOMERANG_ENABLED=true to mail changes.');

            return self::SUCCESS;
        }

        $hasBaseline = Manifest::current() !== null;

        $batch = MailChanges::dispatchSync();

        match (true) {
            $batch instanceof Batch => $this->mailed($batch),
            ! $hasBaseline => $this->components->warn('This server had no baseline, so File Boomerang recorded one and mailed nothing.'),
            default => $this->components->info('Nothing has changed since the last push.'),
        };

        return self::SUCCESS;
    }

    protected function mailed(Batch $batch): void
    {
        $count = $batch->changes->count();

        $this->components->info("Mailed batch {$batch->id} with {$count} ".Str::plural('change', $count).'.');

        $batch->changes->each(fn (Change $change) => $this->components->twoColumnDetail($change->path, $change->type->value));
    }
}
