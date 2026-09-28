<?php

namespace Ahinkle\FileBoomerang;

use Ahinkle\FileBoomerang\Exceptions\UnsafePath;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Statamic\Assets\AssetContainer as Container;
use Statamic\Facades\AssetContainer;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class Paths
{
    /**
     * @return Collection<int, string>
     */
    public static function tracked(): Collection
    {
        return once(fn () => static::withoutNestedRoots(
            collect(config('file-boomerang.paths'))
                ->merge(static::assetContainerRoots())
                ->map(fn (string $path) => trim($path, '/'))
                ->filter(fn (string $path) => $path !== '')
                ->reject(fn (string $path) => static::isInsideGlideCache($path))
                ->unique()
        ));
    }

    public static function allows(string $path): bool
    {
        return static::whyUnsafe($path) === null;
    }

    public static function assertSafe(string $path): string
    {
        if ($reason = static::whyUnsafe($path)) {
            throw new UnsafePath($path, $reason);
        }

        return $path;
    }

    /**
     * @return LazyCollection<string, SplFileInfo>
     */
    public static function files(): LazyCollection
    {
        return LazyCollection::make(function () {
            foreach (static::tracked() as $root) {
                yield from static::filesIn($root);
            }
        })->filter(fn (SplFileInfo $file, string $path) => static::allows($path));
    }

    /**
     * @return LazyCollection<string, SplFileInfo>
     */
    public static function oversized(): LazyCollection
    {
        return static::files()->filter(fn (SplFileInfo $file) => static::isTooLarge($file));
    }

    public static function isTooLarge(SplFileInfo $file): bool
    {
        return $file->getSize() > config('file-boomerang.max_file_size');
    }

    protected static function whyUnsafe(string $path): ?string
    {
        $segments = collect(explode('/', $path));

        return match (true) {
            $path === '' => 'it is empty',
            str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path) === 1 => 'it is absolute',
            str_contains($path, '\\') => 'it contains a backslash',
            preg_match('/[\x00-\x1F\x7F]/', $path) === 1 => 'it contains a control character',
            $segments->intersect(['', '.', '..'])->isNotEmpty() => 'it has an empty, "." or ".." segment',
            $segments->map(fn (string $segment) => Str::lower($segment))->contains('.git') => 'it is inside a .git directory',
            ! static::isTracked($path) => 'it is not inside a tracked path',
            static::isExcluded($path) => 'it is excluded',
            default => null,
        };
    }

    protected static function isTracked(string $path): bool
    {
        return static::tracked()->contains(fn (string $root) => static::isWithin($path, $root));
    }

    protected static function isExcluded(string $path): bool
    {
        return static::isInsideGlideCache($path) || collect(config('file-boomerang.exclude'))
            ->push('.file-boomerang-*', '*/.file-boomerang-*')
            ->contains(fn (string $pattern) => fnmatch($pattern, $path));
    }

    protected static function isInsideGlideCache(string $path): bool
    {
        $cache = static::glideCache();

        return $cache !== null && static::isWithin($path, $cache);
    }

    protected static function glideCache(): ?string
    {
        $cache = config('statamic.assets.image_manipulation.cache');

        return static::relative(match (true) {
            is_string($cache) => (string) config("filesystems.disks.{$cache}.root"),
            (bool) $cache => (string) config('statamic.assets.image_manipulation.cache_path'),
            default => storage_path('statamic/glide'),
        });
    }

    /**
     * @return Collection<int, string>
     */
    protected static function assetContainerRoots(): Collection
    {
        if (! config('file-boomerang.local_asset_containers')) {
            return collect();
        }

        return AssetContainer::all()
            ->map(fn (Container $container) => config("filesystems.disks.{$container->diskHandle()}"))
            ->filter(fn (?array $disk) => ($disk['driver'] ?? null) === 'local')
            ->map(fn (array $disk) => static::relative((string) ($disk['root'] ?? '')))
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, string>  $paths
     * @return Collection<int, string>
     */
    protected static function withoutNestedRoots(Collection $paths): Collection
    {
        return $paths
            ->reject(fn (string $path) => $paths->contains(fn (string $other) => $other !== $path && static::isWithin($path, $other)))
            ->sort()
            ->values();
    }

    protected static function relative(string $absolute): ?string
    {
        $base = rtrim(base_path(), '/').'/';
        $absolute = rtrim($absolute, '/');

        if (! str_starts_with("{$absolute}/", $base) || "{$absolute}/" === $base) {
            return null;
        }

        return Str::after($absolute, $base);
    }

    protected static function isWithin(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, "{$root}/");
    }

    /**
     * @return Generator<string, SplFileInfo>
     */
    protected static function filesIn(string $root): Generator
    {
        $absolute = base_path($root);

        if (is_link($absolute)) {
            return;
        }

        if (is_file($absolute)) {
            yield $root => new SplFileInfo($absolute, '', basename($absolute));

            return;
        }

        if (! is_dir($absolute)) {
            return;
        }

        foreach (Finder::create()->files()->in($absolute)->ignoreDotFiles(false) as $file) {
            if (! $file->isLink()) {
                yield "{$root}/{$file->getRelativePathname()}" => $file;
            }
        }
    }
}
