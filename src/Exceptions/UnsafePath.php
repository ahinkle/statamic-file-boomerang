<?php

namespace Ahinkle\FileBoomerang\Exceptions;

use RuntimeException;

class UnsafePath extends RuntimeException
{
    public function __construct(public readonly string $path, public readonly string $reason)
    {
        parent::__construct("Refusing to touch [{$path}] because {$reason}.");
    }
}
