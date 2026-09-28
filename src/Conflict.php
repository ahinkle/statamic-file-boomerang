<?php

namespace Ahinkle\FileBoomerang;

use Illuminate\Support\Collection;

readonly class Conflict
{
    /**
     * @param  Collection<int, string>  $batchIds
     * @param  Collection<int, Editor>  $editors
     */
    public function __construct(
        public Change $change,
        public ?Editor $editor,
        public Collection $batchIds,
        public Collection $editors,
    ) {}

    public static function from(Change $change, Batch $batch): self
    {
        return new self($change, $batch->editor, collect([$batch->id]), collect([$batch->editor])->filter()->values());
    }

    public function joinedBy(Change $change, Batch $batch): self
    {
        return new self(
            $change,
            $batch->editor,
            $this->batchIds->concat([$batch->id]),
            $this->editors->concat([$batch->editor])->filter()->unique('email')->values(),
        );
    }

    public function path(): string
    {
        return $this->change->path;
    }
}
