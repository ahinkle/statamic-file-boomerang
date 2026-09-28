<?php

namespace Ahinkle\FileBoomerang\Events;

use Illuminate\Foundation\Events\Dispatchable;

class LandingRejected
{
    use Dispatchable;

    public function __construct(public string $reason) {}
}
