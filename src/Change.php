<?php

namespace Ahinkle\FileBoomerang;

use Ahinkle\FileBoomerang\Exceptions\InvalidBatch;

readonly class Change
{
    public function __construct(
        public string $path,
        public ChangeType $type,
        public ?string $blob = null,
        public ?string $base = null,
        public ?int $size = null,
    ) {}

    public static function put(string $path, string $blob, ?string $base, int $size): static
    {
        return new static($path, ChangeType::Put, $blob, $base, $size);
    }

    public static function delete(string $path, string $base): static
    {
        return new static($path, ChangeType::Delete, base: $base);
    }

    public function isPut(): bool
    {
        return $this->type === ChangeType::Put;
    }

    public function isDelete(): bool
    {
        return $this->type === ChangeType::Delete;
    }

    public function isAppliedTo(?string $hash): bool
    {
        return $hash === $this->blob;
    }

    public function fastForwardsFrom(?string $hash): bool
    {
        return $hash === $this->base;
    }

    public function contents(): ?string
    {
        return $this->isPut() ? Blob::contents($this->blob) : null;
    }

    /**
     * @return array{path: string, type: string, blob?: string, base: ?string, size?: int}
     */
    public function toArray(): array
    {
        return match ($this->type) {
            ChangeType::Put => [
                'path' => $this->path,
                'type' => $this->type->value,
                'blob' => $this->blob,
                'base' => $this->base,
                'size' => $this->size,
            ],
            ChangeType::Delete => [
                'path' => $this->path,
                'type' => $this->type->value,
                'base' => $this->base,
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): static
    {
        $path = $attributes['path'] ?? null;

        throw_unless(is_string($path) && $path !== '', InvalidBatch::because('an operation has no path'));

        return match (is_string($attributes['type'] ?? null) ? ChangeType::tryFrom($attributes['type']) : null) {
            ChangeType::Put => static::putFromArray($path, $attributes),
            ChangeType::Delete => static::deleteFromArray($path, $attributes),
            null => throw InvalidBatch::because("the operation on [{$path}] has an unknown type"),
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected static function putFromArray(string $path, array $attributes): static
    {
        throw_unless(GitHash::isValid($attributes['blob'] ?? null), InvalidBatch::because("the put of [{$path}] has no valid blob hash"));
        throw_unless(array_key_exists('base', $attributes), InvalidBatch::because("the put of [{$path}] has no base"));
        throw_unless($attributes['base'] === null || GitHash::isValid($attributes['base']), InvalidBatch::because("the put of [{$path}] has an invalid base hash"));
        throw_unless(is_int($attributes['size'] ?? null) && $attributes['size'] >= 0, InvalidBatch::because("the put of [{$path}] has no valid size"));

        return static::put($path, $attributes['blob'], $attributes['base'], $attributes['size']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected static function deleteFromArray(string $path, array $attributes): static
    {
        throw_unless(GitHash::isValid($attributes['base'] ?? null), InvalidBatch::because("the delete of [{$path}] has no valid base hash"));

        return static::delete($path, $attributes['base']);
    }
}
