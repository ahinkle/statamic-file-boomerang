<?php

namespace Ahinkle\FileBoomerang;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

readonly class LandingRequest
{
    public function __construct(public string $newest, public CarbonImmutable $requestedAt) {}

    public static function latest(): ?self
    {
        if (! Mailbox::disk()->fileExists(static::key())) {
            return null;
        }

        return rescue(fn () => static::fromArray(Mailbox::disk()->json(static::key()) ?? []), report: false);
    }

    public static function record(Batch $newest): self
    {
        return tap(new self($newest->id, now()->toImmutable()))->save();
    }

    public function covers(Batch $batch): bool
    {
        return $this->newest === $batch->id
            && $this->requestedAt->addMinutes(config()->integer('file-boomerang.landing.redispatch_after'))->isFuture();
    }

    public function save(): void
    {
        Mailbox::disk()->put(static::key(), json_encode([
            'newest' => $this->newest,
            'requested_at' => $this->requestedAt->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<mixed>  $attributes
     */
    protected static function fromArray(array $attributes): self
    {
        return new self(Arr::string($attributes, 'newest'), CarbonImmutable::parse(Arr::string($attributes, 'requested_at')));
    }

    protected static function key(): string
    {
        return Mailbox::path('state', 'requested.json');
    }
}
