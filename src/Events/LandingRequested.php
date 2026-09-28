<?php

namespace Ahinkle\FileBoomerang\Events;

use Illuminate\Foundation\Events\Dispatchable;

class LandingRequested
{
    use Dispatchable;

    public function __construct(public string $newest) {}
}
