<?php

namespace Ahinkle\FileBoomerang\Commands;

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Batches;
use Ahinkle\FileBoomerang\Change;
use Ahinkle\FileBoomerang\LandingRequest;
use Ahinkle\FileBoomerang\Mailbox;
use Ahinkle\FileBoomerang\Manifest;
use Ahinkle\FileBoomerang\Paths;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Throwable;

class Status extends Command
{
    use RunsInPlease;

    protected $signature = 'boomerang:status';

    protected $description = 'Show what File Boomerang is waiting on';

    public function handle(): int
    {
        $this->components->twoColumnDetail('Enabled', config('file-boomerang.enabled') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Mailbox', $this->mailbox());
        $this->components->twoColumnDetail('Baseline cursor', $this->cursor());

        rescue(
            fn () => $this->waiting(Batch::pending()),
            fn (Throwable $e) => $this->components->twoColumnDetail('Batches waiting', "<fg=red>the mailbox is unreachable: {$e->getMessage()}</>"),
            report: false,
        );

        $this->tooLarge();

        return self::SUCCESS;
    }

    protected function mailbox(): string
    {
        $disk = config('file-boomerang.mailbox.disk');
        $bucket = config('file-boomerang.mailbox.bucket');

        return match (true) {
            is_string($disk) && $disk !== '' => "the {$disk} disk, under {$this->prefix()}",
            is_string($bucket) && $bucket !== '' => "the {$bucket} bucket, under {$this->prefix()}",
            default => '<fg=yellow>no bucket or disk is set</>',
        };
    }

    protected function prefix(): string
    {
        return Mailbox::path().'/';
    }

    protected function cursor(): string
    {
        $manifest = Manifest::current();

        return match (true) {
            $manifest === null => '<fg=yellow>no baseline on this server</>',
            $manifest->cursor === null => 'recorded before any batch',
            default => $manifest->cursor,
        };
    }

    protected function waiting(Batches $batches): void
    {
        $this->components->twoColumnDetail('Batches waiting', (string) $batches->count());
        $this->components->twoColumnDetail('Last landing request', $this->lastRequest());

        if ($batches->isEmpty()) {
            return;
        }

        $this->components->twoColumnDetail('Oldest', $batches->first()->createdAt()->diffForHumans());
        $this->components->twoColumnDetail('Newest', $batches->newest()?->createdAt()->diffForHumans());
        $this->components->twoColumnDetail('Editors', $batches->editors()->pluck('name')->implode(', ') ?: 'system only');

        $this->paths($batches);
    }

    protected function lastRequest(): string
    {
        $request = LandingRequest::latest();

        if (! $request) {
            return 'never';
        }

        return "{$request->requestedAt->diffForHumans()}, up to {$request->newest}";
    }

    protected function paths(Batches $batches): void
    {
        $this->newLine();
        $this->components->info('Waiting to land');

        $batches
            ->toBase()
            ->flatMap(fn (Batch $batch) => $batch->changes)
            ->keyBy('path')
            ->sortKeys()
            ->each(fn (Change $change) => $this->components->twoColumnDetail($change->path, $change->type->value));
    }

    protected function tooLarge(): void
    {
        $files = Paths::oversized()->keys();

        if ($files->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->warn('Too large to send, over '.config()->integer('file-boomerang.max_file_size').' bytes');

        $files->each(fn (string $path) => $this->components->twoColumnDetail($path, 'skipped'));
    }
}
