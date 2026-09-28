<?php

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Change;
use Ahinkle\FileBoomerang\Editor;
use Ahinkle\FileBoomerang\GitHash;
use Ahinkle\FileBoomerang\Tests\TestCase;
use Illuminate\Support\Carbon;

pest()->extend(TestCase::class)->in(__DIR__);

function edit(string $path, string $contents, ?string $from = null): Change
{
    if ($from !== null) {
        test()->storeBlob($from);
    }

    return Change::put($path, test()->storeBlob($contents), $from === null ? null : GitHash::of($from), strlen($contents));
}

function removal(string $path, string $from): Change
{
    return Change::delete($path, GitHash::of($from));
}

function mailed(Change $change, ?Editor $editor = null): Batch
{
    Carbon::setTestNow(now()->addSecond());

    return Batch::record(collect([$change]), $editor);
}
