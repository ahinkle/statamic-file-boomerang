<?php

namespace Ahinkle\FileBoomerang;

use Ahinkle\FileBoomerang\Exceptions\UnsafePath;
use Illuminate\Support\Collection;

/**
 * @extends Collection<int, Batch>
 */
class Batches extends Collection
{
    public function newest(): ?Batch
    {
        return $this->last();
    }

    public function isQuiet(int $seconds): bool
    {
        if ($this->isEmpty()) {
            return true;
        }

        return $this->newest()->createdAt()->addSeconds($seconds)->lte(now());
    }

    /**
     * @return Collection<int, Editor>
     */
    public function editors(): Collection
    {
        return $this->toBase()->pluck('editor')->filter()->unique('email')->values();
    }

    /**
     * @return Collection<int, string>
     */
    public function blobs(): Collection
    {
        return $this->toBase()
            ->flatMap(fn (Batch $batch) => $batch->changes)
            ->flatMap(fn (Change $change) => [$change->blob, $change->base])
            ->filter()
            ->unique()
            ->values();
    }

    public function applyTo(Tree $tree, Divergence $divergence): Outcome
    {
        return tap(new Outcome($tree, $this->toBase()->pluck('id')), function (Outcome $outcome) use ($divergence) {
            $this->each(fn (Batch $batch) => $batch->changes->each(
                fn (Change $change) => $this->applyChange($change, $batch, $outcome, $divergence)
            ));
        });
    }

    protected function applyChange(Change $change, Batch $batch, Outcome $outcome, Divergence $divergence): void
    {
        if ($reason = $this->whyUnsafe($change->path)) {
            $outcome->skip($change->path, $reason);

            return;
        }

        if ($outcome->isConflicted($change->path)) {
            $outcome->conflict($change, $batch);

            return;
        }

        $hash = $outcome->hash($change->path);

        match (true) {
            $change->isAppliedTo($hash) => null,
            $change->fastForwardsFrom($hash) => $outcome->accept($change),
            default => $this->diverge($change, $batch, $outcome, $divergence),
        };
    }

    protected function diverge(Change $change, Batch $batch, Outcome $outcome, Divergence $divergence): void
    {
        match ($divergence) {
            Divergence::Skip => $outcome->skip($change->path, 'it changed here after the edit was made'),
            Divergence::PreferEditor => $outcome->accept($change),
            Divergence::Merge => $this->mergeInto($outcome, $change, $batch),
        };
    }

    protected function mergeInto(Outcome $outcome, Change $change, Batch $batch): void
    {
        $merged = $this->threeWayMerge($outcome, $change);

        if ($merged === null) {
            $outcome->conflict($change, $batch);

            return;
        }

        $outcome->acceptMerge($change->path, $merged);
    }

    protected function threeWayMerge(Outcome $outcome, Change $change): ?string
    {
        if ($change->isDelete() || $change->base === null) {
            return null;
        }

        $ours = $outcome->contents($change->path);
        $base = $outcome->tree->base($change->base);

        if ($ours === null || $base === null) {
            return null;
        }

        return (new ThreeWayMerge)($ours, $base, $change->contents());
    }

    protected function whyUnsafe(string $path): ?string
    {
        try {
            Paths::assertSafe($path);
        } catch (UnsafePath $e) {
            return $e->reason;
        }

        return null;
    }
}
