<?php

namespace Ahinkle\FileBoomerang\Jobs;

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Batches;
use Ahinkle\FileBoomerang\Events\LandingRejected;
use Ahinkle\FileBoomerang\Events\LandingRequested;
use Ahinkle\FileBoomerang\Exceptions\LandingRejected as LandingRejectedException;
use Ahinkle\FileBoomerang\LandingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

class RequestLanding implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    public function __construct()
    {
        $this->onConnection(config('file-boomerang.queue.connection'));
        $this->onQueue(config('file-boomerang.queue.name'));
    }

    public static function dispatchAfterDebounce(): void
    {
        if (! static::canWait()) {
            return;
        }

        static::dispatch()->delay(now()->addSeconds(config('file-boomerang.debounce')));
    }

    public static function canWait(): bool
    {
        $connection = config('file-boomerang.queue.connection') ?? config('queue.default');

        return config("queue.connections.{$connection}.driver") !== 'sync';
    }

    public function uniqueFor(): int
    {
        return config('file-boomerang.debounce') + 120;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $batches = Batch::pending();

        if ($batches->isEmpty()) {
            return;
        }

        if (! $batches->isQuiet(config('file-boomerang.debounce'))) {
            $this->waitForQuiet($batches->newest());

            return;
        }

        if (LandingRequest::latest()?->covers($batches->newest())) {
            return;
        }

        $this->request($batches);
    }

    protected function waitForQuiet(Batch $newest): void
    {
        if (! static::canWait()) {
            return;
        }

        static::dispatch()->delay($newest->createdAt()->addSeconds(config('file-boomerang.debounce')));
    }

    protected function request(Batches $batches): void
    {
        if (blank(config('file-boomerang.github.repository')) || blank(config('file-boomerang.github.token'))) {
            $this->reject('Set FILE_BOOMERANG_GITHUB_REPOSITORY and FILE_BOOMERANG_GITHUB_TOKEN so File Boomerang can ask GitHub to land the waiting batches.');

            return;
        }

        $response = Http::github()->post($this->dispatchesUrl(), [
            'event_type' => config('file-boomerang.github.event'),
            'client_payload' => ['batches' => $batches->count(), 'newest' => $batches->newest()->id],
        ]);

        match (true) {
            $response->successful() => $this->requested($batches->newest()),
            $this->isWorthRetrying($response) => $response->throw(),
            default => $this->reject($this->reasonFor($response)),
        };
    }

    protected function requested(Batch $newest): void
    {
        LandingRequest::record($newest);

        LandingRequested::dispatch($newest->id);
    }

    protected function reject(string $reason): void
    {
        LandingRejected::dispatch($reason);

        $this->fail(new LandingRejectedException($reason));
    }

    protected function isWorthRetrying(Response $response): bool
    {
        return $response->serverError()
            || $response->tooManyRequests()
            || ($response->forbidden() && $this->isRateLimited($response));
    }

    protected function isRateLimited(Response $response): bool
    {
        return $response->header('Retry-After') !== '' || $response->header('X-RateLimit-Remaining') === '0';
    }

    protected function reasonFor(Response $response): string
    {
        $repository = config('file-boomerang.github.repository');

        return match ($response->status()) {
            401 => 'GitHub did not accept FILE_BOOMERANG_GITHUB_TOKEN. It is missing, expired or revoked, so create a new fine-grained token.',
            403 => "The GitHub token may not send events to {$repository}. Give it the Contents: Read and write permission.",
            404 => "GitHub cannot see {$repository}. Check FILE_BOOMERANG_GITHUB_REPOSITORY and that the token has access to that repository.",
            default => "GitHub refused the landing request with status {$response->status()}: {$response->json('message', $response->body())}",
        };
    }

    protected function dispatchesUrl(): string
    {
        return 'repos/'.config('file-boomerang.github.repository').'/dispatches';
    }
}
