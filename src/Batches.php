<?php

namespace Ahinkle\FileBoomerang;

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
        if (! $newest = $this->newest()) {
            return true;
        }

        return $newest->createdAt()->addSeconds($seconds)->lte(now());
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
            ->flatMap->changes
            ->flatMap(fn (Change $change) => [$change->blob, $change->base])
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, string>  $paths
     */
    public function touching(Collection $paths): static
    {
        return $this->filter(fn (Batch $batch) => $batch->changes->pluck('path')->intersect($paths)->isNotEmpty())->values();
    }

    public function applyTo(Tree $tree, Divergence $divergence): Outcome
    {
        $outcome = new Outcome($tree, $this->toBase()->map(fn (Batch $batch) => $batch->id));

        $this->each(fn (Batch $batch) => $this->applyBatch($batch, $outcome, $divergence));

        return $outcome;
    }

    protected function applyBatch(Batch $batch, Outcome $outcome, Divergence $divergence): void
    {
        $reasons = $batch->changes->mapWithKeys(fn (Change $change) => [$change->path => $this->whySkipped($change)])->filter();

        $reasons->each(fn (string $reason, string $path) => $outcome->skip($path, $reason));

        $changes = $batch->changes->reject(fn (Change $change) => $reasons->has($change->path));

        if ($divergence === Divergence::Merge && $changes->contains(fn (Change $change) => $this->conflicts($change, $outcome))) {
            $changes->each(fn (Change $change) => $outcome->conflict($change, $batch));

            return;
        }

        $changes->each(fn (Change $change) => $this->applyChange($change, $batch, $outcome, $divergence));
    }

    protected function whySkipped(Change $change): ?string
    {
        if ($reason = Paths::whyUnsafe($change->path)) {
            return $reason;
        }

        if ($change->isMissingFromTheMailbox()) {
            return 'its contents are missing from the mailbox';
        }

        return null;
    }

    protected function conflicts(Change $change, Outcome $outcome): bool
    {
        if ($outcome->isConflicted($change->path)) {
            return true;
        }

        $hash = $outcome->hash($change->path);

        if ($change->isAppliedTo($hash) || $change->fastForwardsFrom($hash)) {
            return false;
        }

        return $this->threeWayMerge($outcome, $change) === null;
    }

    protected function applyChange(Change $change, Batch $batch, Outcome $outcome, Divergence $divergence): void
    {
        if ($outcome->isConflicted($change->path)) {
            $outcome->conflict($change, $batch);

            return;
        }

        $hash = $outcome->hash($change->path);

        if ($change->isAppliedTo($hash)) {
            $outcome->find($change);

            return;
        }

        if ($change->fastForwardsFrom($hash)) {
            $outcome->accept($change);

            return;
        }

        $this->diverge($change, $batch, $outcome, $divergence);
    }

    protected function diverge(Change $change, Batch $batch, Outcome $outcome, Divergence $divergence): void
    {
        if ($divergence === Divergence::Skip) {
            $outcome->skip($change->path, 'it changed here after the edit was made, so the landing will merge it');

            return;
        }

        $this->mergeInto($outcome, $change, $batch);
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
        $theirs = $change->contents();

        if ($ours === null || $base === null || $theirs === null) {
            return null;
        }

        return (new ThreeWayMerge)($ours, $base, $theirs);
    }
}
