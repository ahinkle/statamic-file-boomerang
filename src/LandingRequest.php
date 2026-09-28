<?php

namespace Ahinkle\FileBoomerang;

use Carbon\CarbonImmutable;

readonly class LandingRequest
{
    public function __construct(public string $newest, public CarbonImmutable $requestedAt) {}

    public static function latest(): ?static
    {
        if (! Mailbox::disk()->fileExists(static::key())) {
            return null;
        }

        return rescue(fn () => static::fromArray(Mailbox::disk()->json(static::key())), report: false);
    }

    public static function record(Batch $newest): static
    {
        return tap(new static($newest->id, now()->toImmutable()), fn (LandingRequest $request) => $request->save());
    }

    public function covers(Batch $batch): bool
    {
        return $this->newest === $batch->id
            && $this->requestedAt->addMinutes(config('file-boomerang.landing.redispatch_after'))->isFuture();
    }

    /**
     * @param  array{newest: string, requested_at: string}  $attributes
     */
    protected static function fromArray(array $attributes): static
    {
        return new static($attributes['newest'], CarbonImmutable::parse($attributes['requested_at']));
    }

    protected function save(): void
    {
        Mailbox::disk()->put(static::key(), json_encode([
            'newest' => $this->newest,
            'requested_at' => $this->requestedAt->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    protected static function key(): string
    {
        return Mailbox::path('state', 'requested.json');
    }
}
