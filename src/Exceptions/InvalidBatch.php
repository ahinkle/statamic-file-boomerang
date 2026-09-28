<?php

namespace Ahinkle\FileBoomerang\Exceptions;

use RuntimeException;

class InvalidBatch extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("The batch is invalid: {$reason}.");
    }
}
