<?php

use Ahinkle\FileBoomerang\GitHash;
use Illuminate\Support\Facades\Process;

it('agrees with git hash-object', function (string $contents) {
    $path = $this->writeFile('content/sample.md', $contents);

    $expected = trim(Process::run(['git', 'hash-object', '--no-filters', $path])->throw()->output());

    expect(GitHash::ofFile($path))->toBe($expected)
        ->and(GitHash::of($contents))->toBe($expected);
})->with([
    'empty' => '',
    'text' => "---\ntitle: Home\n---\nWelcome to church.\n",
    'binary' => "\x89PNG\r\n\x1a\n\0\0\0\rIHDR".random_bytes(4096),
    'large' => str_repeat("A line of the weekly bulletin.\n", 200000),
]);
