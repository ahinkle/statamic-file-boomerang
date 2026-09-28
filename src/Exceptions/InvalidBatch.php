<?php

namespace Ahinkle\FileBoomerang\Exceptions;

use RuntimeException;

class InvalidBatch extends RuntimeException
{
    public static function because(string $reason): static
    {
        return new static("The batch is invalid: {$reason}.");
    }
}
