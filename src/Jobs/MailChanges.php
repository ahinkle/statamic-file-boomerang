<?php

namespace Ahinkle\FileBoomerang\Jobs;

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Editor;
use Ahinkle\FileBoomerang\Events\BatchMailed;
use Ahinkle\FileBoomerang\Manifest;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class MailChanges
{
    use Dispatchable;

    public function __construct(public ?Editor $editor = null) {}

    public function handle(): ?Batch
    {
        return Manifest::lock(function () {
            $this->catchUp();

            if (! $manifest = Manifest::current()) {
                return $this->recordBaseline();
            }

            return $this->mailChangesSince($manifest);
        });
    }

    protected function catchUp(): void
    {
        if (! config('file-boomerang.catch_up.enabled') || ! Manifest::current()) {
            return;
        }

        rescue(fn () => CatchUp::dispatchSync());
    }

    protected function mailChangesSince(Manifest $manifest): ?Batch
    {
        $changes = $manifest->changes();

        if ($changes->isEmpty()) {
            return null;
        }

        $batch = Batch::record($changes, $this->editor);

        $manifest->withChanges($changes, $batch->id)->save();

        BatchMailed::dispatch($batch);

        RequestLanding::dispatchAfterDebounce();

        return $batch;
    }

    protected function recordBaseline(): null
    {
        Manifest::seed()->save();

        Log::warning('File Boomerang found no baseline on this server, so it recorded one from the files on disk and mailed nothing. Add "php artisan boomerang:pull" to your build so every server starts with one.');

        return null;
    }
}
