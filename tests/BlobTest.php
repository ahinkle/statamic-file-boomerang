<?php

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Batches;
use Ahinkle\FileBoomerang\Blob;
use Ahinkle\FileBoomerang\Change;
use Ahinkle\FileBoomerang\Exceptions\CorruptBlob;
use Ahinkle\FileBoomerang\GitHash;
use Illuminate\Support\Facades\File;

it('stores file bytes under their git hash', function () {
    $path = $this->writeFile('public/img/logo.png', "\x89PNG\r\n\x1a\nlogo");

    $hash = Blob::store($path);

    expect($hash)->toBe(GitHash::of("\x89PNG\r\n\x1a\nlogo"))
        ->and(Blob::exists($hash))->toBeTrue()
        ->and(Blob::contents($hash))->toBe("\x89PNG\r\n\x1a\nlogo")
        ->and($this->mailbox()->files('file-boomerang/blobs'))->toBe(["file-boomerang/blobs/{$hash}"]);
});

it('never rewrites a blob that is already in the mailbox', function () {
    $path = $this->writeFile('content/home.md', 'Welcome');
    $hash = Blob::store($path);

    $this->mailbox()->put("file-boomerang/blobs/{$hash}", 'already here');

    expect(Blob::store($path))->toBe($hash)
        ->and($this->mailbox()->get("file-boomerang/blobs/{$hash}"))->toBe('already here');
});

it('copies a blob into place', function () {
    $hash = $this->storeBlob('New sermon notes');

    Blob::copyTo($hash, base_path('content/sermons/notes.md'));

    expect(File::get(base_path('content/sermons/notes.md')))->toBe('New sermon notes')
        ->and(File::files(base_path('content/sermons')))->toHaveCount(1);
});

it('refuses a corrupt blob and leaves the file as it was', function () {
    $hash = $this->storeBlob('The real notes');
    $this->mailbox()->put("file-boomerang/blobs/{$hash}", 'Tampered notes');
    $path = $this->writeFile('content/sermons/notes.md', 'The old notes');

    expect(fn () => Blob::copyTo($hash, $path))->toThrow(CorruptBlob::class);

    expect(File::get($path))->toBe('The old notes')
        ->and(File::files(dirname($path)))->toHaveCount(1);
});

it('refuses to read a corrupt blob', function () {
    $hash = $this->storeBlob('The real notes');
    $this->mailbox()->put("file-boomerang/blobs/{$hash}", 'Tampered notes');

    expect(fn () => Blob::contents($hash))->toThrow(CorruptBlob::class);
});

it('prunes only unreferenced blobs past the grace period', function () {
    $referenced = $this->storeBlob('still waiting to land');
    $base = $this->storeBlob('the version it was edited from');
    $landed = $this->storeBlob('already landed');
    $fresh = $this->storeBlob('pushed a moment ago');

    collect([$referenced, $base, $landed])->each(fn (string $hash) => touch(
        $this->mailbox()->path("file-boomerang/blobs/{$hash}"), now()->subHours(2)->getTimestamp()
    ));

    $remaining = Batches::make([Batch::record(collect([Change::put('content/a.md', $referenced, $base, 21)]), null)]);

    expect(Blob::prune($remaining, now()->subHour()))->toBe(1)
        ->and(Blob::exists($referenced))->toBeTrue()
        ->and(Blob::exists($base))->toBeTrue()
        ->and(Blob::exists($fresh))->toBeTrue()
        ->and(Blob::exists($landed))->toBeFalse();
});
