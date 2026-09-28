<?php

namespace Ahinkle\FileBoomerang;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\SplFileInfo;

class Manifest
{
    public const int Version = 1;

    protected static bool $locked = false;

    /**
     * @param  Collection<string, array{hash: string, size: int, mtime: ?int}>  $files
     */
    public function __construct(
        public ?string $cursor = null,
        public Collection $files = new Collection,
    ) {}

    public static function current(): ?static
    {
        $manifest = File::isFile(static::path()) ? json_decode(File::get(static::path()), true) : null;

        if (! static::isReadable($manifest)) {
            return null;
        }

        return new static($manifest['cursor'], collect($manifest['files']));
    }

    public static function seed(?string $cursor = null): static
    {
        clearstatcache();

        $startedAt = time();

        return new static($cursor, Paths::files()
            ->reject(fn (SplFileInfo $file) => Paths::isTooLarge($file))
            ->map(fn (SplFileInfo $file) => static::entry(GitHash::ofFile($file->getPathname()), $file, $startedAt))
            ->collect()
            ->sortKeys());
    }

    public static function lock(Closure $callback): mixed
    {
        if (static::$locked) {
            return $callback();
        }

        File::ensureDirectoryExists(dirname(static::path()));

        $handle = fopen(dirname(static::path()).'/file-boomerang.lock', 'c');

        try {
            flock($handle, LOCK_EX);

            static::$locked = true;

            return $callback();
        } finally {
            static::$locked = false;

            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function save(): void
    {
        File::ensureDirectoryExists(dirname(static::path()));

        File::replace(static::path(), json_encode([
            'version' => static::Version,
            'cursor' => $this->cursor,
            'files' => (object) $this->files->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @return Collection<int, Change>
     */
    public function changes(): Collection
    {
        clearstatcache();

        $files = Paths::files()->collect();

        return $this->puts($files)->concat($this->deletes($files))->sortBy('path')->values();
    }

    /**
     * @param  Collection<array-key, Change>  $changes
     */
    public function withChanges(Collection $changes, string $cursor): static
    {
        return new static($cursor, $this->files
            ->merge($changes->filter->isPut()->mapWithKeys(fn (Change $change) => [
                $change->path => ['hash' => $change->blob, 'size' => $change->size, 'mtime' => null],
            ]))
            ->except($changes->filter->isDelete()->pluck('path'))
            ->sortKeys());
    }

    public function hashOf(string $path): ?string
    {
        $absolute = base_path($path);

        if (! is_file($absolute)) {
            return null;
        }

        return $this->stillMatches($path, $absolute) ? $this->known($path) : GitHash::ofFile($absolute);
    }

    public function known(string $path): ?string
    {
        return $this->files->get($path)['hash'] ?? null;
    }

    /**
     * @param  Collection<string, SplFileInfo>  $files
     * @return Collection<int, Change>
     */
    protected function puts(Collection $files): Collection
    {
        return $files
            ->reject(fn (SplFileInfo $file, string $path) => $this->isSkippedForSize($path, $file))
            ->reject(fn (SplFileInfo $file, string $path) => $this->hashOf($path) === $this->known($path))
            ->map(fn (SplFileInfo $file, string $path) => Change::put(
                $path, Blob::store($file->getPathname()), $this->known($path), $file->getSize()
            ))
            ->reject(fn (Change $change) => $change->blob === $change->base)
            ->values();
    }

    /**
     * @param  Collection<string, SplFileInfo>  $files
     * @return Collection<int, Change>
     */
    protected function deletes(Collection $files): Collection
    {
        return $this->files
            ->keys()
            ->reject(fn (string $path) => $files->has($path))
            ->filter(fn (string $path) => Paths::allows($path))
            ->map(fn (string $path) => Change::delete($path, $this->known($path)))
            ->values();
    }

    protected function isSkippedForSize(string $path, SplFileInfo $file): bool
    {
        if (! Paths::isTooLarge($file)) {
            return false;
        }

        Log::warning("File Boomerang skipped [{$path}] because it is larger than the max_file_size of ".config('file-boomerang.max_file_size').' bytes.');

        return true;
    }

    protected function stillMatches(string $path, string $absolute): bool
    {
        $entry = $this->files->get($path);

        return $entry !== null
            && $entry['mtime'] !== null
            && $entry['size'] === filesize($absolute)
            && $entry['mtime'] === filemtime($absolute);
    }

    /**
     * @return array{hash: string, size: int, mtime: ?int}
     */
    protected static function entry(string $hash, SplFileInfo $file, int $hashedAt): array
    {
        return [
            'hash' => $hash,
            'size' => $file->getSize(),
            'mtime' => $file->getMTime() < $hashedAt ? $file->getMTime() : null,
        ];
    }

    protected static function isReadable(mixed $manifest): bool
    {
        return is_array($manifest)
            && ($manifest['version'] ?? null) === static::Version
            && array_key_exists('cursor', $manifest)
            && ($manifest['cursor'] === null || is_string($manifest['cursor']))
            && is_array($manifest['files'] ?? null)
            && collect($manifest['files'])->every(fn (mixed $entry) => static::isEntry($entry));
    }

    protected static function isEntry(mixed $entry): bool
    {
        return is_array($entry)
            && GitHash::isValid($entry['hash'] ?? null)
            && is_int($entry['size'] ?? null)
            && array_key_exists('mtime', $entry)
            && ($entry['mtime'] === null || is_int($entry['mtime']));
    }

    protected static function path(): string
    {
        return config('file-boomerang.manifest');
    }
}
