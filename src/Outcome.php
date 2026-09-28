<?php

namespace Ahinkle\FileBoomerang;

use Illuminate\Support\Collection;

readonly class Outcome
{
    /**
     * @param  Collection<int, string>  $batchIds
     * @param  Collection<string, Change>  $changes
     * @param  Collection<string, string>  $merged
     * @param  Collection<string, Conflict>  $conflicts
     * @param  Collection<string, string>  $skipped
     * @param  Collection<string, Change>  $found
     * @param  Collection<string, ?string>  $originals
     * @param  Collection<string, ?string>  $hashes
     */
    public function __construct(
        public Tree $tree,
        public Collection $batchIds,
        public Collection $changes = new Collection,
        public Collection $merged = new Collection,
        public Collection $conflicts = new Collection,
        public Collection $skipped = new Collection,
        protected Collection $found = new Collection,
        protected Collection $originals = new Collection,
        protected Collection $hashes = new Collection,
    ) {}

    public function hash(string $path): ?string
    {
        return $this->hashes->getOrPut($path, fn () => $this->original($path));
    }

    public function contents(string $path): ?string
    {
        return match (true) {
            $this->merged->has($path) => $this->merged->get($path),
            $this->changes->has($path) => $this->changes->get($path)?->contents(),
            default => $this->tree->contents($path),
        };
    }

    public function accept(Change $change): void
    {
        $this->settle($change->path, $change->blob);

        if ($this->isChanged($change->path)) {
            $this->changes->put($change->path, $change);
        }
    }

    public function acceptMerge(string $path, string $contents): void
    {
        $this->settle($path, GitHash::of($contents));

        if ($this->isChanged($path)) {
            $this->merged->put($path, $contents);
        }
    }

    public function find(Change $change): void
    {
        $this->found->put($change->path, $change);
    }

    public function conflict(Change $change, Batch $batch): void
    {
        $conflict = $this->conflicts->get($change->path);

        $this->conflicts->put($change->path, $conflict?->joinedBy($change, $batch) ?? Conflict::from($change, $batch));
    }

    public function skip(string $path, string $reason): void
    {
        $this->skipped->put($path, $reason);
    }

    public function isConflicted(string $path): bool
    {
        return $this->conflicts->has($path);
    }

    /**
     * @return Collection<int, string>
     */
    public function paths(): Collection
    {
        return $this->changes->keys()->concat($this->merged->keys())->sort()->values();
    }

    public function write(): void
    {
        $this->changes->each->writeTo($this->tree);

        $this->merged->each(fn (string $contents, string $path) => $this->tree->write($path, $contents));
    }

    /**
     * @return Collection<string, Change>
     */
    public function inTree(): Collection
    {
        return $this->found
            ->merge($this->changes)
            ->filter(fn (Change $change) => $change->isAppliedTo($this->tree->hash($change->path)));
    }

    protected function original(string $path): ?string
    {
        return $this->originals->getOrPut($path, fn () => $this->tree->hash($path));
    }

    protected function settle(string $path, ?string $hash): void
    {
        $this->original($path);

        $this->changes->forget($path);
        $this->merged->forget($path);
        $this->found->forget($path);
        $this->hashes->put($path, $hash);
    }

    protected function isChanged(string $path): bool
    {
        return $this->hashes->get($path) !== $this->originals->get($path);
    }
}
