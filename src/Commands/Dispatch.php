<?php

namespace Ahinkle\FileBoomerang\Commands;

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Batches;
use Ahinkle\FileBoomerang\Events\LandingRejected;
use Ahinkle\FileBoomerang\Jobs\RequestLanding;
use Ahinkle\FileBoomerang\LandingRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Statamic\Console\RunsInPlease;

class Dispatch extends Command
{
    use RunsInPlease;

    protected $signature = 'boomerang:dispatch';

    protected $description = 'Ask GitHub to land the waiting batches once the editors have gone quiet';

    protected ?string $rejection = null;

    public function handle(): int
    {
        if (! config('file-boomerang.enabled')) {
            $this->components->warn('File Boomerang is disabled. Set FILE_BOOMERANG_ENABLED=true to request landings.');

            return self::SUCCESS;
        }

        Event::listen(LandingRejected::class, fn (LandingRejected $event) => $this->rejection = $event->reason);

        RequestLanding::dispatchSync();

        if ($this->rejection) {
            $this->components->error($this->rejection);

            return self::FAILURE;
        }

        $this->describe(Batch::pending());

        return self::SUCCESS;
    }

    protected function describe(Batches $batches): void
    {
        if (! $newest = $batches->newest()) {
            $this->components->info('Nothing is waiting in the mailbox.');

            return;
        }

        $waiting = $batches->count().' '.Str::plural('batch', $batches->count());

        $this->components->info(match (true) {
            ! $batches->isQuiet($this->debounce()) => "{$waiting} waiting. GitHub will be asked {$this->quietAt($newest)}, once the editors go quiet.",
            default => "{$waiting} waiting. GitHub was asked {$this->requestedAt()} to land them.",
        });
    }

    protected function quietAt(Batch $newest): string
    {
        return $newest->createdAt()->addSeconds($this->debounce())->diffForHumans();
    }

    protected function debounce(): int
    {
        return config()->integer('file-boomerang.debounce');
    }

    protected function requestedAt(): string
    {
        return LandingRequest::latest()?->requestedAt->diffForHumans() ?? 'earlier';
    }
}
