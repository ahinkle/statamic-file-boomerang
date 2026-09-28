<?php

namespace Ahinkle\FileBoomerang\Commands;

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Divergence;
use Ahinkle\FileBoomerang\Manifest;
use Ahinkle\FileBoomerang\Outcome;
use Ahinkle\FileBoomerang\WorkingTree;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Statamic\Console\RunsInPlease;
use Throwable;

class Pull extends Command
{
    use RunsInPlease;

    protected $signature = 'boomerang:pull
        {--seed-only : Record the baseline without applying the waiting edits}';

    protected $description = 'Apply the edits waiting in the mailbox and record this build\'s baseline';

    public function handle(): int
    {
        if ($this->option('seed-only') || ! config('file-boomerang.enabled')) {
            return $this->seed();
        }

        return rescue(fn () => $this->pull(), fn (Throwable $e) => $this->unreachable($e), report: false);
    }

    protected function seed(): int
    {
        Manifest::lock(fn () => Manifest::seed()->save());

        $this->components->info('Recorded a baseline of the tracked files without reading the mailbox.');

        return self::SUCCESS;
    }

    protected function pull(): int
    {
        $outcome = Manifest::lock(function () {
            $batches = Batch::pending();

            return tap($batches->applyTo(new WorkingTree, Divergence::PreferEditor), function (Outcome $outcome) use ($batches) {
                $outcome->writeTo($outcome->tree);

                Manifest::seed($batches->newest()?->id)->save();
            });
        });

        $this->pulled($outcome);

        return self::SUCCESS;
    }

    protected function pulled(Outcome $outcome): void
    {
        $count = $outcome->paths()->count();

        $this->components->info("Applied {$count} ".Str::plural('file', $count).' from '.$outcome->batchIds->count().' waiting '.Str::plural('batch', $outcome->batchIds->count()).' and recorded the baseline.');

        $outcome->paths()->each(fn (string $path) => $this->components->twoColumnDetail($path, 'applied'));

        $outcome->skipped->each(fn (string $reason, string $path) => $this->components->twoColumnDetail($path, "skipped because {$reason}"));
    }

    protected function unreachable(Throwable $e): int
    {
        $this->components->error("File Boomerang could not read the mailbox, so this build stops before it loses any waiting edits. {$e->getMessage()}");

        $this->line('  Check the FILE_BOOMERANG_* mailbox settings in the build environment, or run "php artisan boomerang:doctor".');

        return self::FAILURE;
    }
}
