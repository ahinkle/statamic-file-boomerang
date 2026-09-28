<?php

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Change;
use Ahinkle\FileBoomerang\Editor;
use Ahinkle\FileBoomerang\GitHash;
use Ahinkle\FileBoomerang\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;

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

function remote(): string
{
    return dirname(base_path()).'/remote.git';
}

function pushToRemote(): void
{
    test()->git('init', '--quiet', '--initial-branch=main');
    test()->git('add', '--all');
    test()->git('commit', '--quiet', '-m', 'Initial commit');
    test()->git('init', '--quiet', '--bare', '--initial-branch=main', remote());
    test()->git('remote', 'add', 'origin', remote());
    test()->git('push', '--quiet', '--set-upstream', 'origin', 'main');
}

function onRemote(string ...$arguments): string
{
    return trim(Process::path(remote())->run(['git', ...$arguments])->throw()->output());
}

function fileOnRemote(string $path, string $branch = 'main'): ?string
{
    $result = Process::path(remote())->run(['git', 'show', "{$branch}:{$path}"]);

    return $result->successful() ? $result->output() : null;
}
