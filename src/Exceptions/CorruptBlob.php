<?php

namespace Ahinkle\FileBoomerang\Exceptions;

use RuntimeException;

class CorruptBlob extends RuntimeException
{
    public function __construct(public readonly string $hash, public readonly string $actual)
    {
        parent::__construct("Blob [{$hash}] in the mailbox does not match its hash. Its bytes hash to [{$actual}].");
    }
}
