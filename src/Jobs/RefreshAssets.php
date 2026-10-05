<?php

namespace Ahinkle\FileBoomerang\Jobs;

use Ahinkle\FileBoomerang\Paths;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Assets\AssetContainer as Container;
use Statamic\Facades\Stache;

class RefreshAssets
{
    use Dispatchable;

    /**
     * @param  Collection<int, string>  $paths
     */
    public function __construct(public Container $container, public Collection $paths) {}

    public function handle(): void
    {
        if (! $root = Paths::assetContainerRoot($this->container)) {
            return;
        }

        $files = $this->paths
            ->filter(fn (string $path) => str_starts_with($path, "{$root}/"))
            ->map(fn (string $path) => Str::after($path, "{$root}/"));

        if ($files->isEmpty()) {
            return;
        }

        $files->each(fn (string $path) => $this->refresh($path, base_path("{$root}/{$path}")));

        $this->container->contents()->save();

        Stache::store("assets::{$this->container->handle()}")->clear();
    }

    protected function refresh(string $path, string $absolutePath): void
    {
        $asset = $this->container->makeAsset($this->assetPath($path));

        $asset->cacheStore()->forget($asset->metaCacheKey());

        if (File::exists($absolutePath)) {
            $this->container->contents()->add($path);

            return;
        }

        $this->container->contents()->forget($path);
    }

    protected function assetPath(string $path): string
    {
        return Str::of($path)->replaceMatches('#(^|/)\.meta/([^/]+)\.yaml$#', '$1$2')->value();
    }
}
