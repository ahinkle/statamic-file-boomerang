<?php

namespace Ahinkle\FileBoomerang\Events;

use Ahinkle\FileBoomerang\Batch;
use Illuminate\Foundation\Events\Dispatchable;

class BatchMailed
{
    use Dispatchable;

    public function __construct(public Batch $batch) {}
}
