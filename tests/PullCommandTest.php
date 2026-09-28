<?php

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Change;
use Ahinkle\FileBoomerang\Editor;
use Ahinkle\FileBoomerang\GitHash;
use Ahinkle\FileBoomerang\Manifest;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    config(['file-boomerang.enabled' => true]);

    $this->writeFile('content/pages/home.md', 'Welcome');
});

it('applies every waiting edit, preferring the editor, and records the baseline', function () {
    config(['file-boomerang.paths' => ['content', 'public/media']]);
    Batch::record(collect([
        Change::put('content/pages/home.md', $this->storeBlob('Welcome home'), GitHash::of('Welcome, friends'), 12),
        Change::put('public/media/bulletin.pdf', $this->storeBlob('%PDF bulletin'), null, 13),
    ]), new Editor('Andy Hinkle', 'andy@example.com'));
    $newest = Batch::record(collect([
        Change::delete('content/pages/old.md', GitHash::of('Old news')),
    ]), null);
    $this->writeFile('content/pages/old.md', 'Old news');

    $this->artisan('boomerang:pull')->assertSuccessful();

    expect(File::get(base_path('content/pages/home.md')))->toBe('Welcome home')
        ->and(File::get(base_path('public/media/bulletin.pdf')))->toBe('%PDF bulletin')
        ->and(File::exists(base_path('content/pages/old.md')))->toBeFalse()
        ->and(Manifest::current())
        ->cursor->toBe($newest->id)
        ->known('content/pages/home.md')->toBe(GitHash::of('Welcome home'));
});

it('records the baseline when nothing is waiting', function () {
    $this->artisan('boomerang:pull')->assertSuccessful();

    expect(Manifest::current())
        ->cursor->toBeNull()
        ->known('content/pages/home.md')->toBe(GitHash::of('Welcome'));
});

it('stops the build when the mailbox cannot be read', function () {
    config(['file-boomerang.mailbox.disk' => 'missing']);

    $this->artisan('boomerang:pull')
        ->expectsOutputToContain('could not read the mailbox')
        ->assertFailed();

    expect(Manifest::current())->toBeNull();
});

it('only records the baseline while disabled', function () {
    Batch::record(collect([
        Change::put('content/pages/home.md', $this->storeBlob('Welcome home'), GitHash::of('Welcome'), 12),
    ]), null);
    config(['file-boomerang.enabled' => false]);

    $this->artisan('boomerang:pull')->assertSuccessful();

    expect(File::get(base_path('content/pages/home.md')))->toBe('Welcome')
        ->and(Manifest::current()->known('content/pages/home.md'))->toBe(GitHash::of('Welcome'));
});

it('only records the baseline when asked to', function () {
    config(['file-boomerang.mailbox.disk' => 'missing']);

    $this->artisan('boomerang:pull', ['--seed-only' => true])->assertSuccessful();

    expect(Manifest::current()->known('content/pages/home.md'))->toBe(GitHash::of('Welcome'));
});
