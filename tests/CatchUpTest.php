<?php

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Change;
use Ahinkle\FileBoomerang\GitHash;
use Ahinkle\FileBoomerang\Jobs\CatchUp;
use Ahinkle\FileBoomerang\Manifest;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

function mailedFromAnotherServer(string $path, string $contents): Batch
{
    $current = is_file(base_path($path)) ? GitHash::ofFile(base_path($path)) : null;

    return Batch::record(collect([
        Change::put($path, test()->storeBlob($contents), $current, strlen($contents)),
    ]), null);
}

beforeEach(function () {
    config(['filesystems.disks.images' => ['driver' => 'local', 'root' => public_path('img')]]);

    $this->writeFile('public/img/logo.png', 'PNG logo');
    $this->writeFile('public/img/.meta/logo.png.yaml', "data:\n  alt: 'Our logo'\n");
    AssetContainer::make('images')->disk('images')->save();
    Collection::make('pages')->save();
    Entry::make()->collection('pages')->id('home')->slug('home')->data(['title' => 'Home'])->save();

    Manifest::seed()->save();

    config(['file-boomerang.enabled' => true]);
});

it('fast-forwards edits mailed by another server and remembers them', function () {
    $batch = mailedFromAnotherServer('content/pages/about.md', 'About us');

    CatchUp::dispatchSync();

    expect(File::get(base_path('content/pages/about.md')))->toBe('About us')
        ->and(Manifest::current())
        ->cursor->toBe($batch->id)
        ->known('content/pages/about.md')->toBe(GitHash::of('About us'));
});

it('leaves a file alone when it changed here after the edit was made', function () {
    mailedFromAnotherServer('content/pages/about.md', 'About us');
    $this->writeFile('content/pages/about.md', 'Our story');

    $outcome = CatchUp::dispatchSync();

    expect(File::get(base_path('content/pages/about.md')))->toBe('Our story')
        ->and($outcome->skipped->all())->toBe(['content/pages/about.md' => 'it changed here after the edit was made']);
});

it('only applies batches it has not seen yet', function () {
    mailedFromAnotherServer('content/pages/about.md', 'About us');
    CatchUp::dispatchSync();
    $this->writeFile('content/pages/about.md', 'Our story');

    expect(CatchUp::dispatchSync())->toBeNull()
        ->and(File::get(base_path('content/pages/about.md')))->toBe('Our story');
});

it('shows a caught up entry without a stache refresh', function () {
    $path = 'content/collections/pages/home.md';
    expect(Entry::find('home')->get('title'))->toBe('Home');

    mailedFromAnotherServer($path, str_replace('title: Home', 'title: Welcome home', File::get(base_path($path))));
    CatchUp::dispatchSync();

    expect(Entry::find('home')->get('title'))->toBe('Welcome home')
        ->and(Entry::query()->where('title', 'Welcome home')->count())->toBe(1);
});

it('shows caught up uploads and alt text in the asset listing', function () {
    $container = AssetContainer::find('images');
    expect($container->files()->all())->toBe(['logo.png'])
        ->and(Asset::find('images::logo.png')->get('alt'))->toBe('Our logo');

    mailedFromAnotherServer('public/img/staff/greg.jpg', 'JPEG greg');
    mailedFromAnotherServer('public/img/.meta/logo.png.yaml', "data:\n  alt: 'The church logo'\n");
    CatchUp::dispatchSync();

    expect(AssetContainer::find('images')->files()->all())->toBe(['logo.png', 'staff/greg.jpg'])
        ->and(Asset::find('images::logo.png')->get('alt'))->toBe('The church logo');
});

it('moves caught up pages to their new urls', function () {
    Collection::make('sections')->routes('{parent_uri}/{slug}')->structureContents(['max_depth' => 3])->save();
    Entry::make()->collection('sections')->id('about')->slug('about')->data(['title' => 'About'])->save();
    Entry::make()->collection('sections')->id('team')->slug('team')->data(['title' => 'Team'])->save();
    Collection::find('sections')->structure()->in('default')->tree([['entry' => 'about', 'children' => [['entry' => 'team']]]])->save();
    Manifest::seed()->save();
    expect(Entry::findByUri('/about/team')?->id())->toBe('team');

    mailedFromAnotherServer('content/trees/collections/sections.yaml', "tree:\n  -\n    entry: about\n  -\n    entry: team\n");
    CatchUp::dispatchSync();

    expect(Entry::findByUri('/team')?->id())->toBe('team')
        ->and(Entry::findByUri('/about/team'))->toBeNull();
});
