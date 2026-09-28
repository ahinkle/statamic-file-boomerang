<?php

use Ahinkle\FileBoomerang\Blob;
use Ahinkle\FileBoomerang\Change;
use Ahinkle\FileBoomerang\GitHash;
use Ahinkle\FileBoomerang\Manifest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

function manifestPath(): string
{
    return storage_path('framework/file-boomerang.json');
}

it('seeds a baseline from the tracked files on disk', function () {
    config(['file-boomerang.max_file_size' => 100]);

    $this->writeFile('content/pages/home.md', 'Welcome', 1727460000);
    $this->writeFile('content/video.mp4', str_repeat('x', 101));
    $this->writeFile('app/Secret.php', '<?php');

    Manifest::seed('01J8ZQ6X9R6B7Y5M3N2P1K0H9G')->save();

    expect(json_decode(File::get(manifestPath()), true))->toBe([
        'version' => 1,
        'cursor' => '01J8ZQ6X9R6B7Y5M3N2P1K0H9G',
        'files' => [
            'content/pages/home.md' => ['hash' => GitHash::of('Welcome'), 'size' => 7, 'mtime' => 1727460000],
        ],
    ]);
});

it('reads back the baseline it saved', function () {
    $this->writeFile('content/pages/home.md', 'Welcome');

    Manifest::seed()->save();

    expect(Manifest::current())
        ->cursor->toBeNull()
        ->known('content/pages/home.md')->toBe(GitHash::of('Welcome'));
});

it('has no baseline when the manifest is missing or unreadable', function (?string $contents) {
    if ($contents !== null) {
        File::ensureDirectoryExists(dirname(manifestPath()));
        File::put(manifestPath(), $contents);
    }

    expect(Manifest::current())->toBeNull();
})->with([
    'missing' => null,
    'not json' => '{"version": 1, "cursor": null, "fil',
    'another version' => '{"version": 2, "cursor": null, "files": {}}',
    'no cursor' => '{"version": 1, "files": {}}',
    'an entry without a hash' => '{"version": 1, "cursor": null, "files": {"content/a.md": {"size": 1, "mtime": null}}}',
]);

it('finds edits, additions and deletions since the baseline', function () {
    $this->writeFile('content/pages/home.md', 'Welcome');
    $this->writeFile('content/pages/about.md', 'About us');
    $this->writeFile('content/pages/old.md', 'Old news');
    $manifest = Manifest::seed();

    $this->writeFile('content/pages/home.md', 'Welcome home');
    $this->writeFile('content/pages/new.md', 'Brand new');
    File::delete(base_path('content/pages/old.md'));

    expect($manifest->changes())->toEqual(collect([
        Change::put('content/pages/home.md', GitHash::of('Welcome home'), GitHash::of('Welcome'), 12),
        Change::put('content/pages/new.md', GitHash::of('Brand new'), null, 9),
        Change::delete('content/pages/old.md', GitHash::of('Old news')),
    ]));
});

it('mails the bytes of every file it reports as changed', function () {
    config(['file-boomerang.paths' => ['public/media']]);
    $manifest = Manifest::seed();

    $this->writeFile('public/media/bulletin.pdf', '%PDF-1.7 bulletin');

    $change = $manifest->changes()->sole();

    expect(Blob::contents($change->blob))->toBe('%PDF-1.7 bulletin');
});

it('trusts the baseline for files whose size and modified time have not moved', function () {
    $this->writeFile('content/pages/home.md', 'Welcome', 1727460000);
    $manifest = Manifest::seed();

    $this->writeFile('content/pages/home.md', 'Welkom!', 1727460000);

    expect($manifest->changes())->toBeEmpty();
});

it('rehashes a file saved in the same second it was recorded', function () {
    $later = time() + 5;

    $this->writeFile('content/pages/home.md', 'Welcome', $later);
    $manifest = Manifest::seed();

    $this->writeFile('content/pages/home.md', 'Welkom!', $later);

    expect($manifest->changes()->pluck('path')->all())->toBe(['content/pages/home.md']);
});

it('does not send the same change twice', function () {
    $this->writeFile('content/pages/home.md', 'Welcome');
    $manifest = Manifest::seed();

    $this->writeFile('content/pages/home.md', 'Welcome home');
    File::delete(base_path('content/pages/home.md'));
    $this->writeFile('content/pages/new.md', 'New');

    $changes = $manifest->changes();
    $manifest = $manifest->withChanges($changes, '01J8ZQ6X9R6B7Y5M3N2P1K0H9G');

    expect($changes)->toHaveCount(2)
        ->and($manifest->cursor)->toBe('01J8ZQ6X9R6B7Y5M3N2P1K0H9G')
        ->and($manifest->changes())->toBeEmpty();
});

it('skips files over the size limit and says so', function () {
    Log::spy();
    config(['file-boomerang.max_file_size' => 10, 'file-boomerang.paths' => ['public']]);

    $manifest = Manifest::seed();
    $this->writeFile('public/video.mp4', str_repeat('x', 11));

    expect($manifest->changes())->toBeEmpty();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'public/video.mp4'))->once();
});

it('leaves out deletions of paths it no longer tracks', function () {
    config(['file-boomerang.paths' => ['content']]);

    $manifest = new Manifest(files: collect([
        'resources/forms/contact.yaml' => ['hash' => GitHash::of('title: Contact'), 'size' => 14, 'mtime' => 1727460000],
    ]));

    expect($manifest->changes())->toBeEmpty();
});

it('runs one push at a time on a container', function () {
    File::ensureDirectoryExists(storage_path('framework'));

    $lock = storage_path('framework/file-boomerang.lock');
    $released = storage_path('framework/released');

    $holder = Process::start([PHP_BINARY, '-r', <<<PHP
        \$handle = fopen('{$lock}', 'c');
        flock(\$handle, LOCK_EX);
        echo 'locked';
        usleep(500000);
        file_put_contents('{$released}', 'yes');
        flock(\$handle, LOCK_UN);
        PHP]);

    $holder->waitUntil(fn (string $type, string $output) => str_contains($output, 'locked'));

    expect(Manifest::lock(fn () => File::exists($released)))->toBeTrue();

    $holder->wait();
});

it('lets a push nested inside a lock run without waiting on itself', function () {
    expect(Manifest::lock(fn () => Manifest::lock(fn () => 'done')))->toBe('done');
});
