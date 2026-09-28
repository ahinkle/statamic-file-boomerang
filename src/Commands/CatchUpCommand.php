<?php

namespace Ahinkle\FileBoomerang\Commands;

use Ahinkle\FileBoomerang\Jobs\CatchUp;
use Ahinkle\FileBoomerang\Outcome;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Statamic\Console\RunsInPlease;

class CatchUpCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'boomerang:catch-up';

    protected $description = 'Apply edits other servers mailed since this one last looked';

    public function handle(): int
    {
        if (! config('file-boomerang.enabled')) {
            $this->components->warn('File Boomerang is disabled. Set FILE_BOOMERANG_ENABLED=true to catch up.');

            return self::SUCCESS;
        }

        $outcome = CatchUp::dispatchSync();

        if (! $outcome instanceof Outcome) {
            $this->components->info('This server is already up to date.');

            return self::SUCCESS;
        }

        $this->caughtUp($outcome);

        return self::SUCCESS;
    }

    protected function caughtUp(Outcome $outcome): void
    {
        $count = $outcome->paths()->count();

        $this->components->info("Applied {$count} ".Str::plural('file', $count).' from '.$outcome->batchIds->count().' '.Str::plural('batch', $outcome->batchIds->count()).'.');

        $outcome->paths()->each(fn (string $path) => $this->components->twoColumnDetail($path, 'applied'));

        $outcome->skipped->each(fn (string $reason, string $path) => $this->components->twoColumnDetail($path, "skipped because {$reason}"));
    }
}
