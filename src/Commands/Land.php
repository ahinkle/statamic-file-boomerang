<?php

namespace Ahinkle\FileBoomerang\Commands;

use Ahinkle\FileBoomerang\Exceptions\LandingRejected;
use Ahinkle\FileBoomerang\Landing;
use Ahinkle\FileBoomerang\LandingResult;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

class Land extends Command
{
    use RunsInPlease;

    protected $signature = 'boomerang:land {--dry-run : Show what would land without writing anything}';

    protected $description = 'Commit the batches waiting in the mailbox and push them to the branch';

    public function handle(): int
    {
        $landing = Landing::make()->onto(config()->string('file-boomerang.github.branch'));

        try {
            $result = $this->option('dry-run') ? $landing->dryRun() : $landing->land();
        } catch (LandingRejected $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->report($result);

        return self::SUCCESS;
    }

    protected function report(LandingResult $result): void
    {
        $result->paths->each(fn (string $path) => $this->components->twoColumnDetail($path, '<fg=green>changed</>'));
        $result->conflicts->keys()->each(fn (string $path) => $this->components->twoColumnDetail($path, '<fg=yellow>conflict</>'));
        $result->skipped->each(fn (string $reason, string $path) => $this->components->twoColumnDetail($path, "<fg=gray>skipped because {$reason}</>"));

        $this->components->info($result->summary());

        if ($result->conflictsUrl) {
            $this->components->warn("The conflicts are waiting for review at {$result->conflictsUrl}");
        }
    }
}
