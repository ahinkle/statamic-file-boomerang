<?php

namespace Ahinkle\FileBoomerang;

use Illuminate\Support\Collection;

readonly class Outcome
{
    /**
     * @var Collection<string, Change>
     */
    public Collection $changes;

    /**
     * @var Collection<string, string>
     */
    public Collection $merged;

    /**
     * @var Collection<string, Conflict>
     */
    public Collection $conflicts;

    /**
     * @var Collection<string, string>
     */
    public Collection $skipped;

    /**
     * @var Collection<string, ?string>
     */
    protected Collection $originals;

    /**
     * @var Collection<string, ?string>
     */
    protected Collection $hashes;

    /**
     * @param  Collection<int, string>  $batchIds
     */
    public function __construct(public Tree $tree, public Collection $batchIds)
    {
        $this->changes = new Collection;
        $this->merged = new Collection;
        $this->conflicts = new Collection;
        $this->skipped = new Collection;
        $this->originals = new Collection;
        $this->hashes = new Collection;
    }

    public function hash(string $path): ?string
    {
        return $this->hashes->getOrPut($path, fn () => $this->original($path));
    }

    public function contents(string $path): ?string
    {
        return match (true) {
            $this->merged->has($path) => $this->merged->get($path),
            $this->changes->has($path) => $this->changes->get($path)->contents(),
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

    public function writeTo(Tree $tree): void
    {
        $this->changes->each->writeTo($tree);

        $this->merged->each(fn (string $contents, string $path) => $tree->write($path, $contents));
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
        $this->hashes->put($path, $hash);
    }

    protected function isChanged(string $path): bool
    {
        return $this->hashes->get($path) !== $this->originals->get($path);
    }
}
