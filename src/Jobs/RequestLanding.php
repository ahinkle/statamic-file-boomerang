<?php

namespace Ahinkle\FileBoomerang\Jobs;

use Ahinkle\FileBoomerang\Batch;
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
        $this->onConnection(static::configured('file-boomerang.queue.connection'));
        $this->onQueue(static::configured('file-boomerang.queue.name'));
    }

    public static function dispatchAfterDebounce(): void
    {
        if (! static::canWait()) {
            return;
        }

        static::dispatch()->delay(now()->addSeconds(static::debounce()));
    }

    public static function canWait(): bool
    {
        $connection = static::configured('file-boomerang.queue.connection') ?? config()->string('queue.default');

        return config("queue.connections.{$connection}.driver") !== 'sync';
    }

    public function uniqueFor(): int
    {
        return static::debounce() + 120;
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

        if (! $newest = $batches->newest()) {
            return;
        }

        if (! $batches->isQuiet(static::debounce())) {
            $this->waitForQuiet($newest);

            return;
        }

        if (LandingRequest::latest()?->covers($newest)) {
            return;
        }

        $this->request($newest);
    }

    protected function waitForQuiet(Batch $newest): void
    {
        if (! static::canWait()) {
            return;
        }

        static::dispatch()->delay($newest->createdAt()->addSeconds(static::debounce()));
    }

    protected function request(Batch $newest): void
    {
        if (blank(config('file-boomerang.github.repository')) || blank(config('file-boomerang.github.token'))) {
            $this->reject('Set FILE_BOOMERANG_GITHUB_REPOSITORY and FILE_BOOMERANG_GITHUB_TOKEN so File Boomerang can ask GitHub to land the waiting batches.');

            return;
        }

        $response = Http::github()->post("repos/{$this->repository()}/actions/workflows/file-boomerang.yml/dispatches", [
            'ref' => config()->string('file-boomerang.github.branch'),
        ]);

        if ($response->successful()) {
            $this->requested($newest);

            return;
        }

        if ($this->isWorthRetrying($response)) {
            $response->throw();
        }

        $this->reject($this->reasonFor($response));
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
        return match (true) {
            $response->serverError(), $response->tooManyRequests() => true,
            $response->forbidden() => $this->isRateLimited($response),
            default => false,
        };
    }

    protected function isRateLimited(Response $response): bool
    {
        if ($response->header('Retry-After') !== '') {
            return true;
        }

        return $response->header('X-RateLimit-Remaining') === '0';
    }

    protected function reasonFor(Response $response): string
    {
        return match ($response->status()) {
            401 => 'GitHub did not accept FILE_BOOMERANG_GITHUB_TOKEN. It is missing, expired or revoked, so create a new fine-grained token.',
            403 => "The GitHub token may not start workflows in {$this->repository()}. Give it the Actions: Read and write permission.",
            404 => "GitHub cannot find .github/workflows/file-boomerang.yml in {$this->repository()}. Check FILE_BOOMERANG_GITHUB_REPOSITORY, that the token has access to that repository, and that the workflow is on the default branch.",
            default => "GitHub refused the landing request with status {$response->status()}: {$response->fluent()->string('message', $response->body())}",
        };
    }

    protected function repository(): string
    {
        return config()->string('file-boomerang.github.repository');
    }

    protected static function configured(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected static function debounce(): int
    {
        return config()->integer('file-boomerang.debounce');
    }
}
