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
        public ?string $conflictsUrl = null,
    ) {}

    public static function from(Outcome $outcome, ?string $sha = null, ?string $conflictsUrl = null): static
    {
        return new static($outcome->batchIds, $outcome->paths(), $outcome->conflicts, $outcome->skipped, $sha, $conflictsUrl);
    }

    public function isEmpty(): bool
    {
        return $this->batchIds->isEmpty();
    }

    public function landed(): bool
    {
        return $this->sha !== null && $this->paths->isNotEmpty();
    }

    public function summary(): string
    {
        $files = $this->paths->count().' '.Str::plural('file', $this->paths->count());
        $batches = $this->batchIds->count().' '.Str::plural('batch', $this->batchIds->count());

        return match (true) {
            $this->isEmpty() => 'Nothing is waiting in the mailbox.',
            $this->sha === null => "Would land {$files} from {$batches}.",
            $this->landed() => "Landed {$files} from {$batches} in ".Str::substr($this->sha, 0, 7).'.',
            default => "Nothing new to land from {$batches}.",
        };
    }
}
