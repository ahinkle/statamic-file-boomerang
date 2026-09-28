<?php

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Change;
use Ahinkle\FileBoomerang\GitHash;
use Ahinkle\FileBoomerang\Manifest;
use Illuminate\Support\Facades\File;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

function andy(): UserContract
{
    return User::make()->id('andy')->email('andy@example.com')->set('name', 'Andy Hinkle');
}

beforeEach(function () {
    Collection::make('pages')->save();
    Entry::make()->collection('pages')->id('home')->slug('home')->data(['title' => 'Home'])->save();

    Manifest::seed()->save();

    config(['file-boomerang.enabled' => true]);
});

it('mails one batch with the saved entry, its base and its editor', function () {
    $home = base_path('content/collections/pages/home.md');
    $before = GitHash::ofFile($home);
    $this->actingAs(andy());

    Entry::find('home')->set('title', 'Welcome home')->save();

    $batch = Batch::pending()->sole();

    expect($batch->editor->toArray())->toBe(['name' => 'Andy Hinkle', 'email' => 'andy@example.com'])
        ->and($batch->changes->all())->toEqual([
            Change::put('content/collections/pages/home.md', GitHash::ofFile($home), $before, filesize($home)),
        ])
        ->and(Manifest::current()->cursor)->toBe($batch->id);
});

it('mails system writes without an editor', function () {
    Entry::find('home')->set('title', 'Archived')->save();

    expect(Batch::pending()->sole()->editor)->toBeNull();
});

it('only mails what changed since the last push', function () {
    Entry::find('home')->set('title', 'Welcome home')->save();
    Entry::make()->collection('pages')->id('about')->slug('about')->data(['title' => 'About'])->save();

    expect(Batch::pending()->map(fn (Batch $batch) => $batch->changes->pluck('path')->all())->all())->toBe([
        ['content/collections/pages/home.md'],
        ['content/collections/pages/about.md'],
    ]);
});

it('records a baseline and mails nothing when this server has none', function () {
    File::delete(config('file-boomerang.manifest'));

    Entry::find('home')->set('title', 'Welcome home')->save();

    expect(Batch::pending())->toBeEmpty()
        ->and(Manifest::current()->known('content/collections/pages/home.md'))
        ->toBe(GitHash::ofFile(base_path('content/collections/pages/home.md')));
});

it('mails nothing while it is disabled', function () {
    config(['file-boomerang.enabled' => false]);

    Entry::find('home')->set('title', 'Welcome home')->save();

    expect(Batch::pending())->toBeEmpty();
});

it('mails every save in a request as one batch after the response', function () {
    (fn () => $this->isRunningInConsole = false)->call($this->app);
    $this->actingAs(andy());

    Entry::find('home')->set('title', 'Welcome home')->save();
    Entry::make()->collection('pages')->id('about')->slug('about')->data(['title' => 'About'])->save();

    expect(Batch::pending())->toBeEmpty()
        ->and(defer())->toHaveCount(1);

    defer()->invoke();

    expect(Batch::pending()->sole())
        ->editor->email->toBe('andy@example.com')
        ->changes->pluck('path')->all()->toBe([
            'content/collections/pages/about.md',
            'content/collections/pages/home.md',
        ]);
});
