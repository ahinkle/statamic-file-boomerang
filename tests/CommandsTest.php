<?php

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Change;
use Ahinkle\FileBoomerang\Editor;
use Ahinkle\FileBoomerang\GitHash;
use Ahinkle\FileBoomerang\Manifest;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'file-boomerang.enabled' => true,
        'file-boomerang.github.repository' => 'ahinkle/sccc.org',
        'file-boomerang.github.token' => 'github_pat_owner',
    ]);

    $this->writeFile('content/pages/home.md', 'Welcome');

    Manifest::seed()->save();
});

it('pushes the changes on this server and lists them', function () {
    $this->writeFile('content/pages/home.md', 'Welcome home');

    $this->artisan('boomerang:push')
        ->expectsOutputToContain('with 1 change')
        ->expectsOutputToContain('content/pages/home.md')
        ->assertSuccessful();

    expect(Batch::pending()->sole()->editor)->toBeNull();
});

it('catches up from the command line', function () {
    Batch::record(collect([
        Change::put('content/pages/about.md', $this->storeBlob('About us'), null, 8),
    ]), null);

    $this->artisan('boomerang:catch-up')
        ->expectsOutputToContain('content/pages/about.md')
        ->assertSuccessful();

    expect(File::get(base_path('content/pages/about.md')))->toBe('About us');
});

it('says why github refused a landing and fails', function () {
    Http::fake(['api.github.com/*' => Http::response(status: 401)]);
    Batch::record(collect([Change::put('content/pages/home.md', $this->storeBlob('Welcome home'), GitHash::of('Welcome'), 12)]), null);
    $this->travel(config('file-boomerang.debounce') + 1)->seconds();

    $this->artisan('boomerang:dispatch')
        ->expectsOutputToContain('GitHub did not accept FILE_BOOMERANG_GITHUB_TOKEN')
        ->assertFailed();
});

it('says when it asked github to land the batches', function () {
    Http::fake(['api.github.com/*' => Http::response(status: 204)]);
    Batch::record(collect([Change::put('content/pages/home.md', $this->storeBlob('Welcome home'), GitHash::of('Welcome'), 12)]), null);
    $this->travel(config('file-boomerang.debounce') + 1)->seconds();

    $this->artisan('boomerang:dispatch')
        ->expectsOutputToContain('1 batch waiting. GitHub was asked')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('shows what is waiting without printing secrets', function () {
    config(['file-boomerang.mailbox.secret' => 'r2-secret']);
    Batch::record(collect([
        Change::put('content/pages/home.md', $this->storeBlob('Welcome home'), GitHash::of('Welcome'), 12),
    ]), new Editor('Andy Hinkle', 'andy@example.com'));

    $this->artisan('boomerang:status')
        ->expectsOutputToContain('Andy Hinkle')
        ->expectsOutputToContain('content/pages/home.md')
        ->doesntExpectOutputToContain('r2-secret')
        ->doesntExpectOutputToContain('github_pat_owner')
        ->assertSuccessful();
});

it('passes the doctor when everything is in place', function () {
    config(['cache.default' => 'database', 'queue.default' => 'database', 'session.driver' => 'database']);
    Http::fake(['api.github.com/*' => Http::response(['id' => 1])]);

    $this->artisan('boomerang:doctor')
        ->doesntExpectOutputToContain('FAIL')
        ->doesntExpectOutputToContain('WARN')
        ->assertSuccessful();
});

it('tells the owner how to fix what is missing', function () {
    config([
        'file-boomerang.github.token' => null,
        'file-boomerang.debounce' => 900,
        'session.driver' => 'file',
    ]);
    File::delete(config('file-boomerang.manifest'));

    $this->artisan('boomerang:doctor')
        ->expectsOutputToContain('Add "php artisan boomerang:pull" to the end of your build command.')
        ->expectsOutputToContain('Set FILE_BOOMERANG_GITHUB_TOKEN')
        ->expectsOutputToContain('Set FILE_BOOMERANG_DEBOUNCE below 900.')
        ->expectsOutputToContain('File sessions are wiped on every deploy')
        ->assertFailed();
});

it('asks for landings every minute only while enabled', function (bool $enabled) {
    config(['file-boomerang.enabled' => $enabled]);

    $dispatch = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains($event->command, 'boomerang:dispatch'));

    expect($dispatch->expression)->toBe('* * * * *')
        ->and($dispatch->filtersPass(app()))->toBe($enabled);
})->with([true, false]);
