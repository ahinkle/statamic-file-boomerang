<?php

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Change;
use Ahinkle\FileBoomerang\GitHash;
use Ahinkle\FileBoomerang\Manifest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

function retitleHome(string $title): void
{
    $path = base_path('content/collections/pages/home.md');
    $contents = preg_replace('/^title: .*$/m', "title: '{$title}'", File::get($path));

    Batch::record(collect([
        Change::put('content/collections/pages/home.md', test()->storeBlob($contents), GitHash::ofFile($path), strlen($contents)),
    ]), null);
}

beforeEach(function () {
    Collection::make('pages')->save();
    Entry::make()->collection('pages')->id('home')->slug('home')->data(['title' => 'Home'])->save();

    Manifest::seed()->save();

    config(['file-boomerang.enabled' => true]);

    $this->actingAs(User::make()->id('andy')->email('andy@example.com')->makeSuper());
});

it('shows edits from another server in the control panel', function () {
    $this->get(cp_route('collections.entries.edit', ['pages', 'home']))->assertOk()->assertDontSee('Welcome home');

    retitleHome('Welcome home');
    $this->travel(16)->seconds();

    $this->get(cp_route('collections.entries.edit', ['pages', 'home']))->assertOk()->assertSee('Welcome home');
});

it('looks in the mailbox at most once per interval', function () {
    $this->get(cp_route('collections.entries.edit', ['pages', 'home']));
    retitleHome('Welcome home');

    $this->travel(10)->seconds();
    $this->get(cp_route('collections.entries.edit', ['pages', 'home']))->assertDontSee('Welcome home');

    $this->travel(6)->seconds();
    $this->get(cp_route('collections.entries.edit', ['pages', 'home']))->assertSee('Welcome home');
});

it('never catches up on the request that saves, so a concurrent edit is merged rather than lost', function () {
    $path = base_path('content/collections/pages/home.md');
    $original = GitHash::ofFile($path);
    $this->get(cp_route('collections.entries.edit', ['pages', 'home']))->assertOk();

    Batch::record(collect([
        Change::put('content/collections/pages/home.md', $this->storeBlob(File::get($path)."summary: 'From another server'\n"), $original, 1),
    ]), null);
    $this->travel(16)->seconds();

    $this->patchJson(cp_route('collections.entries.update', ['pages', 'home']), [
        'title' => 'Welcome home',
        'slug' => 'home',
        'published' => true,
    ])->assertOk();

    expect(Batch::pending()->last()->changes->sole())
        ->path->toBe('content/collections/pages/home.md')
        ->base->toBe($original);
});

it('keeps the control panel working when the mailbox is unreachable', function () {
    Log::spy();
    config(['file-boomerang.mailbox.disk' => 'missing']);

    $this->get(cp_route('collections.entries.edit', ['pages', 'home']))->assertOk()->assertSee('Home');

    Log::shouldHaveReceived('error')->once();
});
