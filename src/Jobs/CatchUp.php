<?php

namespace Ahinkle\FileBoomerang\Jobs;

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Batches;
use Ahinkle\FileBoomerang\Divergence;
use Ahinkle\FileBoomerang\Manifest;
use Ahinkle\FileBoomerang\Outcome;
use Ahinkle\FileBoomerang\WorkingTree;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Assets\AssetContainer as Container;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Stache;
use Statamic\Stache\Stores\Store;

class CatchUp
{
    use Dispatchable;

    public function handle(): ?Outcome
    {
        return Manifest::lock(function () {
            $manifest = Manifest::current() ?? tap(Manifest::seed())->save();

            $batches = Batch::after($manifest->cursor);

            if ($batches->isEmpty()) {
                return null;
            }

            return $this->apply($batches, $manifest);
        });
    }

    protected function apply(Batches $batches, Manifest $manifest): Outcome
    {
        $outcome = $batches->applyTo(new WorkingTree($manifest), Divergence::Skip);

        $outcome->writeTo($outcome->tree);

        $manifest->withChanges($outcome->changes, $batches->newest()->id)->save();

        $this->refreshStatamic($outcome->paths()->map(fn (string $path) => base_path($path)));

        return $outcome;
    }

    /**
     * @param  Collection<int, string>  $paths
     */
    protected function refreshStatamic(Collection $paths): void
    {
        if ($paths->contains(fn (string $path) => $this->isInStache($path))) {
            Stache::clear();
        }

        AssetContainer::all()
            ->filter(fn (Container $container) => config("filesystems.disks.{$container->diskHandle()}.driver") === 'local')
            ->each(fn (Container $container) => $this->refreshAssets(
                $container, $paths->filter(fn (string $path) => str_starts_with($path, $container->diskPath().'/'))
            ));
    }

    protected function isInStache(string $path): bool
    {
        return Stache::stores()
            ->map(fn (Store $store) => $store->directory())
            ->filter()
            ->contains(fn (string $directory) => str_starts_with($path, $directory));
    }

    /**
     * @param  Collection<int, string>  $paths
     */
    protected function refreshAssets(Container $container, Collection $paths): void
    {
        if ($paths->isEmpty()) {
            return;
        }

        $paths
            ->map(fn (string $path) => Str::after($path, $container->diskPath().'/'))
            ->each(fn (string $path) => $this->refreshAsset($container, $path));

        $container->contents()->save();

        Stache::store("assets::{$container->handle()}")->clear();
    }

    protected function refreshAsset(Container $container, string $path): void
    {
        $asset = $container->makeAsset(Str::replaceMatches('#(^|/)\.meta/([^/]+)\.yaml$#', '$1$2', $path));

        $asset->cacheStore()->forget($asset->metaCacheKey());

        if ($container->disk()->exists($path)) {
            $container->contents()->add($path);

            return;
        }

        $container->contents()->forget($path);
    }
}
