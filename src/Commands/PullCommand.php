<?php

namespace Ahinkle\FileBoomerang\Commands;

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Batches;
use Ahinkle\FileBoomerang\Divergence;
use Ahinkle\FileBoomerang\Exceptions\CorruptBlob;
use Ahinkle\FileBoomerang\Exceptions\InvalidBatch;
use Ahinkle\FileBoomerang\Manifest;
use Ahinkle\FileBoomerang\Outcome;
use Ahinkle\FileBoomerang\WorkingTree;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Console\RunsInPlease;
use Throwable;

class PullCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'boomerang:pull
        {--seed-only : Record the baseline without applying the waiting edits}';

    protected $description = 'Apply the edits waiting in the mailbox and record this build\'s baseline';

    public function handle(): int
    {
        config(['statamic.stache.cache_store' => 'array']);

        if ($this->option('seed-only') || ! config('file-boomerang.enabled')) {
            return $this->seed();
        }

        try {
            $ids = Batch::ids();
        } catch (Throwable $e) {
            return $this->unreachable($e);
        }

        return $this->pull($ids);
    }

    protected function seed(): int
    {
        Manifest::lock(fn () => Manifest::seed()->save());

        $this->components->info('Recorded a baseline of the tracked files without reading the mailbox.');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, string>  $ids
     */
    protected function pull(Collection $ids): int
    {
        try {
            $outcome = Manifest::lock(fn () => $this->applyWaitingEdits($this->readable($ids), $ids->last()));
        } catch (CorruptBlob $corruptBlob) {
            return $this->corrupt($corruptBlob);
        }

        $this->pulled($outcome);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, string>  $ids
     */
    protected function readable(Collection $ids): Batches
    {
        return Batches::make($ids->map($this->find(...))->filter()->values());
    }

    protected function find(string $id): ?Batch
    {
        try {
            return Batch::find($id);
        } catch (InvalidBatch $invalidBatch) {
            $this->components->warn("Skipped a batch no build can apply. {$invalidBatch->getMessage()}");

            return null;
        }
    }

    protected function applyWaitingEdits(Batches $batches, ?string $cursor): Outcome
    {
        $outcome = $batches->applyTo(new WorkingTree, Divergence::Skip);

        $outcome->write();

        Manifest::seed($cursor)->save();

        return $outcome;
    }

    protected function pulled(Outcome $outcome): void
    {
        if ($outcome->batchIds->isEmpty()) {
            $this->components->info('Nothing is waiting in the mailbox. Recorded the baseline.');

            return;
        }

        $count = $outcome->paths()->count();

        $this->components->info("Applied {$count} ".Str::plural('file', $count).' from '.$outcome->batchIds->count().' waiting '.Str::plural('batch', $outcome->batchIds->count()).' and recorded the baseline.');

        $outcome->paths()->each(fn (string $path) => $this->components->twoColumnDetail($path, 'applied'));

        $outcome->skipped->each(fn (string $reason, string $path) => $this->components->twoColumnDetail($path, "skipped because {$reason}"));
    }

    protected function unreachable(Throwable $e): int
    {
        $this->components->error("File Boomerang could not list the waiting edits in the mailbox, so this build stops before it loses any. {$e->getMessage()}");

        $this->line('  Check the FILE_BOOMERANG_* mailbox settings in the build environment, or run "php artisan boomerang:doctor".');

        return self::FAILURE;
    }

    protected function corrupt(CorruptBlob $corruptBlob): int
    {
        $this->components->error("{$corruptBlob->getMessage()} This build stops before it ships a damaged file.");

        $this->line('  Delete the batches that use it from the mailbox, or run "php artisan boomerang:pull --seed-only" in this build once.');

        return self::FAILURE;
    }
}
