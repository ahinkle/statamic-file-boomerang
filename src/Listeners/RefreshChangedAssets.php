<?php

namespace Ahinkle\FileBoomerang\Listeners;

use Ahinkle\FileBoomerang\Jobs\RefreshAssets;
use Ahinkle\FileBoomerang\Paths;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Statamic\Assets\AssetContainer as Container;
use Statamic\Facades\AssetContainer;
use Symfony\Component\Finder\SplFileInfo;

class RefreshChangedAssets
{
    public function handle(): void
    {
        if (! config('file-boomerang.enabled')) {
            return;
        }

        clearstatcache();

        AssetContainer::all()
            ->whereInstanceOf(Container::class)
            ->each(fn (Container $container) => RefreshAssets::dispatchSync($container, $this->changed($container)));
    }

    /**
     * @return Collection<int, string>
     */
    protected function changed(Container $container): Collection
    {
        if (! $root = Paths::assetContainerRoot($container)) {
            return new Collection;
        }

        $remembered = $container->contents()->all();

        return $remembered->keys()
            ->merge($this->onDisk($root))
            ->unique()
            ->reject(fn (string $path) => $this->matches($remembered->get($path), base_path("{$root}/{$path}")))
            ->map(fn (string $path) => "{$root}/{$path}")
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    protected function onDisk(string $root): Collection
    {
        if (! File::isDirectory(base_path($root))) {
            return new Collection;
        }

        return collect(File::allFiles(base_path($root), true))
            ->map(fn (SplFileInfo $file) => str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname()));
    }

    /**
     * @param  array{type: string, timestamp: ?int, size?: int}|null  $remembered
     */
    protected function matches(?array $remembered, string $absolutePath): bool
    {
        return match (true) {
            $remembered === null, ! File::exists($absolutePath) => false,
            $remembered['type'] !== 'file' => true,
            default => $remembered['timestamp'] === File::lastModified($absolutePath)
                && ($remembered['size'] ?? null) === File::size($absolutePath),
        };
    }
}
