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

function breakTheDefaultCache(): void
{
    config([
        'database.connections.missing' => ['driver' => 'sqlite', 'database' => dirname(base_path()).'/missing/database.sqlite'],
        'cache.stores.database-cache' => ['driver' => 'database', 'connection' => 'missing', 'table' => 'cache'],
        'cache.default' => 'database-cache',
        'statamic.stache.cache_store' => null,
    ]);
}

it('applies every waiting edit to files git has not changed since, and records the baseline', function () {
    config(['file-boomerang.paths' => ['content', 'public/media']]);
    Batch::record(collect([
        Change::put('content/pages/home.md', $this->storeBlob('Welcome home'), GitHash::of('Welcome'), 12),
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

it('keeps what git has when it changed after the edit was made', function () {
    $this->writeFile('content/pages/home.md', "Welcome\nMerged by the landing\n");
    Batch::record(collect([
        Change::put('content/pages/home.md', $this->storeBlob('Welcome home'), GitHash::of('Welcome'), 12),
    ]), null);

    $this->artisan('boomerang:pull')
        ->expectsOutputToContain('the landing will merge it')
        ->assertSuccessful();

    expect(File::get(base_path('content/pages/home.md')))->toBe("Welcome\nMerged by the landing\n");
});

it('records the baseline when nothing is waiting', function () {
    $this->artisan('boomerang:pull')->assertSuccessful();

    expect(Manifest::current())
        ->cursor->toBeNull()
        ->known('content/pages/home.md')->toBe(GitHash::of('Welcome'));
});

it('skips a batch no build can apply and applies the rest', function () {
    Batch::record(collect([
        Change::put('content/pages/home.md', $this->storeBlob('Welcome home'), GitHash::of('Welcome'), 12),
    ]), null);
    $this->mailbox()->put('file-boomerang/batches/01J8ZQ6X9R6B7Y5M3N2P1K0H9G.json', json_encode(['version' => 2]));

    $this->artisan('boomerang:pull')
        ->expectsOutputToContain('file-boomerang/batches/01J8ZQ6X9R6B7Y5M3N2P1K0H9G.json')
        ->assertSuccessful();

    expect(File::get(base_path('content/pages/home.md')))->toBe('Welcome home')
        ->and($this->mailbox()->exists('file-boomerang/batches/01J8ZQ6X9R6B7Y5M3N2P1K0H9G.json'))->toBeTrue();
});

it('stops the build when the mailbox cannot be listed', function () {
    config(['file-boomerang.mailbox.disk' => 'missing']);

    $this->artisan('boomerang:pull')
        ->expectsOutputToContain('could not list the waiting edits in the mailbox')
        ->assertFailed();

    expect(Manifest::current())->toBeNull();
});

it('stops the build and names a damaged file instead of shipping it', function () {
    $hash = $this->storeBlob('Welcome home');
    Batch::record(collect([Change::put('content/pages/home.md', $hash, GitHash::of('Welcome'), 12)]), null);
    $this->mailbox()->put("file-boomerang/blobs/{$hash}", 'Tampered');

    $this->artisan('boomerang:pull')
        ->expectsOutputToContain($hash)
        ->expectsOutputToContain('--seed-only')
        ->assertFailed();

    expect(File::get(base_path('content/pages/home.md')))->toBe('Welcome');
});

it('applies the waiting edits when the build cannot reach the app cache', function () {
    breakTheDefaultCache();
    Batch::record(collect([
        Change::put('content/pages/home.md', $this->storeBlob('Welcome home'), GitHash::of('Welcome'), 12),
    ]), null);

    $this->artisan('boomerang:pull')->assertSuccessful();

    expect(File::get(base_path('content/pages/home.md')))->toBe('Welcome home')
        ->and(Manifest::current()->known('content/pages/home.md'))->toBe(GitHash::of('Welcome home'));
});

it('records the baseline when the build cannot reach the app cache', function () {
    breakTheDefaultCache();

    $this->artisan('boomerang:pull', ['--seed-only' => true])->assertSuccessful();

    expect(Manifest::current()->known('content/pages/home.md'))->toBe(GitHash::of('Welcome'));
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
