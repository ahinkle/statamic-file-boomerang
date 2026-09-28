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

    public static function put(string $path, string $blob, ?string $base, int $size): self
    {
        return new self($path, ChangeType::Put, $blob, $base, $size);
    }

    public static function delete(string $path, string $base): self
    {
        return new self($path, ChangeType::Delete, base: $base);
    }

    /**
     * @phpstan-assert-if-true !null $this->blob
     * @phpstan-assert-if-true !null $this->size
     */
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

    public function writeTo(Tree $tree): void
    {
        if ($this->isPut()) {
            $tree->put($this->path, $this->blob);

            return;
        }

        $tree->delete($this->path);
    }

    /**
     * @return array{path: string, type: string, blob?: ?string, base: ?string, size?: ?int}
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
     * @param  array<mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $path = $attributes['path'] ?? null;
        $type = $attributes['type'] ?? null;

        throw_unless(is_string($path) && $path !== '', InvalidBatch::because('an operation has no path'));

        return match (is_string($type) ? ChangeType::tryFrom($type) : null) {
            ChangeType::Put => static::putFromArray($path, $attributes),
            ChangeType::Delete => static::deleteFromArray($path, $attributes),
            null => throw InvalidBatch::because("the operation on [{$path}] has an unknown type"),
        };
    }

    /**
     * @param  array<mixed>  $attributes
     */
    protected static function putFromArray(string $path, array $attributes): self
    {
        $blob = $attributes['blob'] ?? null;
        $base = $attributes['base'] ?? null;
        $size = $attributes['size'] ?? null;

        throw_unless(GitHash::isValid($blob), InvalidBatch::because("the put of [{$path}] has no valid blob hash"));
        throw_unless(array_key_exists('base', $attributes), InvalidBatch::because("the put of [{$path}] has no base"));
        throw_unless($base === null || GitHash::isValid($base), InvalidBatch::because("the put of [{$path}] has an invalid base hash"));
        throw_unless(is_int($size) && $size >= 0, InvalidBatch::because("the put of [{$path}] has no valid size"));

        return static::put($path, $blob, $base, $size);
    }

    /**
     * @param  array<mixed>  $attributes
     */
    protected static function deleteFromArray(string $path, array $attributes): self
    {
        $base = $attributes['base'] ?? null;

        throw_unless(GitHash::isValid($base), InvalidBatch::because("the delete of [{$path}] has no valid base hash"));

        return static::delete($path, $base);
    }
}
