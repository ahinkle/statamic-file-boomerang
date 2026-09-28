<?php

namespace Ahinkle\FileBoomerang\Jobs;

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Divergence;
use Ahinkle\FileBoomerang\Manifest;
use Ahinkle\FileBoomerang\Outcome;
use Ahinkle\FileBoomerang\Paths;
use Ahinkle\FileBoomerang\WorkingTree;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Assets\AssetContainer as Container;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Stache;
use Throwable;

class CatchUp
{
    use Dispatchable;

    public function handle(): ?Outcome
    {
        return Manifest::lock(fn () => $this->catchUp(Manifest::current() ?? tap(Manifest::seed())->save()));
    }

    protected function catchUp(Manifest $manifest): ?Outcome
    {
        $batches = Batch::after($manifest->cursor);

        if (! $newest = $batches->newest()) {
            return null;
        }

        $outcome = $batches->applyTo(new WorkingTree($manifest), Divergence::Skip);

        try {
            $outcome->write();
        } catch (Throwable $e) {
            $this->remember($outcome, $manifest, $manifest->cursor);

            throw $e;
        }

        $this->remember($outcome, $manifest, $newest->id);

        return $outcome;
    }

    protected function remember(Outcome $outcome, Manifest $manifest, ?string $cursor): void
    {
        $inTree = $outcome->inTree();

        $manifest->withChanges($inTree, $cursor)->save();

        $this->refreshStatamic($outcome->paths()->intersect($inTree->keys())->values());
    }

    /**
     * @param  Collection<int, string>  $paths
     */
    protected function refreshStatamic(Collection $paths): void
    {
        if ($paths->contains(fn (string $path) => $this->isInStache(base_path($path)))) {
            Stache::clear();
        }

        AssetContainer::all()
            ->whereInstanceOf(Container::class)
            ->each(fn (Container $container) => $this->refreshAssets($container, $paths));
    }

    protected function isInStache(string $path): bool
    {
        return Stache::stores()
            ->except('assets')
            ->map->directory()
            ->filter()
            ->contains(fn (string $directory) => str_starts_with($path, $directory));
    }

    /**
     * @param  Collection<int, string>  $paths
     */
    protected function refreshAssets(Container $container, Collection $paths): void
    {
        if (! $root = Paths::assetContainerRoot($container)) {
            return;
        }

        $files = $paths
            ->filter(fn (string $path) => str_starts_with($path, "{$root}/"))
            ->map(fn (string $path) => Str::after($path, "{$root}/"));

        if ($files->isEmpty()) {
            return;
        }

        $files->each(fn (string $path) => $this->refreshAsset($container, $path, base_path("{$root}/{$path}")));

        $container->contents()->save();

        Stache::store("assets::{$container->handle()}")->clear();
    }

    protected function refreshAsset(Container $container, string $path, string $absolutePath): void
    {
        $asset = $container->makeAsset($this->assetPath($path));

        $asset->cacheStore()->forget($asset->metaCacheKey());

        if (File::exists($absolutePath)) {
            $container->contents()->add($path);

            return;
        }

        $container->contents()->forget($path);
    }

    protected function assetPath(string $path): string
    {
        return Str::of($path)->replaceMatches('#(^|/)\.meta/([^/]+)\.yaml$#', '$1$2')->value();
    }
}
