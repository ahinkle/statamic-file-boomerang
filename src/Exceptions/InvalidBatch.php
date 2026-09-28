<?php

namespace Ahinkle\FileBoomerang\Exceptions;

use RuntimeException;

class InvalidBatch extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?string $key = null)
    {
        parent::__construct($key === null
            ? "The batch is invalid: {$reason}."
            : "The batch [{$key}] is invalid: {$reason}. Delete it from the mailbox once you have checked it.");
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function in(string $key): self
    {
        return new self($this->reason, $key);
    }
}
