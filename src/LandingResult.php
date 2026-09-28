<?php

namespace Ahinkle\FileBoomerang;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

readonly class LandingResult
{
    /**
     * @param  Collection<int, string>  $batchIds
     * @param  Collection<int, string>  $paths
     * @param  Collection<string, Conflict>  $conflicts
     * @param  Collection<string, string>  $skipped
     */
    public function __construct(
        public Collection $batchIds = new Collection,
        public Collection $paths = new Collection,
        public Collection $conflicts = new Collection,
        public Collection $skipped = new Collection,
        public ?string $sha = null,
        public ?string $conflictsBranch = null,
        public ?string $conflictsUrl = null,
    ) {}

    public static function from(Outcome $outcome, ?string $sha = null, ?string $conflictsBranch = null, ?string $conflictsUrl = null): self
    {
        return new self($outcome->batchIds, $outcome->paths(), $outcome->conflicts, $outcome->skipped, $sha, $conflictsBranch, $conflictsUrl);
    }

    /**
     * @phpstan-assert-if-true !null $this->conflictsBranch
     */
    public function hasUnreportedConflicts(): bool
    {
        return $this->conflictsBranch !== null && $this->conflictsUrl === null;
    }

    public function isEmpty(): bool
    {
        return $this->batchIds->isEmpty();
    }

    public function isDryRun(): bool
    {
        return $this->sha === null;
    }

    /**
     * @phpstan-assert-if-true !null $this->sha
     */
    public function landed(): bool
    {
        return ! $this->isDryRun() && $this->paths->isNotEmpty();
    }

    public function summary(): string
    {
        $files = $this->paths->count().' '.Str::plural('file', $this->paths->count());
        $batches = $this->batchIds->count().' '.Str::plural('batch', $this->batchIds->count());

        return match (true) {
            $this->isEmpty() => 'Nothing is waiting in the mailbox.',
            $this->isDryRun() => "Would land {$files} from {$batches}.",
            $this->landed() => "Landed {$files} from {$batches} in ".Str::substr($this->sha, 0, 7).'.',
            default => "Nothing new to land from {$batches}.",
        };
    }
}
