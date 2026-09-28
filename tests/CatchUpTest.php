<?php

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Change;
use Ahinkle\FileBoomerang\Exceptions\CorruptBlob;
use Ahinkle\FileBoomerang\GitHash;
use Ahinkle\FileBoomerang\Jobs\CatchUp;
use Ahinkle\FileBoomerang\Jobs\MailChanges;
use Ahinkle\FileBoomerang\Manifest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blink;
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
        ->and($outcome->skipped->all())->toBe(['content/pages/about.md' => 'it changed here after the edit was made, so the landing will merge it']);
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

it('shows caught up uploads and alt text in the asset listing on the next request', function () {
    expect(AssetContainer::find('images')->queryAssets()->get()->map->path()->all())->toBe(['logo.png'])
        ->and(Asset::find('images::logo.png')->get('alt'))->toBe('Our logo');

    mailedFromAnotherServer('public/img/staff/greg.jpg', 'JPEG greg');
    mailedFromAnotherServer('public/img/.meta/logo.png.yaml', "data:\n  alt: 'The site logo'\n");
    CatchUp::dispatchSync();
    Blink::flush();

    expect(AssetContainer::find('images')->queryAssets()->get()->map->path()->sort()->values()->all())->toBe(['logo.png', 'staff/greg.jpg'])
        ->and(Asset::find('images::logo.png')->get('alt'))->toBe('The site logo');
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

it('remembers what it wrote before a damaged file stopped it', function () {
    mailedFromAnotherServer('content/pages/about.md', 'About us');
    $damaged = mailedFromAnotherServer('content/pages/team.md', 'Our team');
    $this->mailbox()->put('file-boomerang/blobs/'.$damaged->changes->sole()->blob, 'Tampered');
    $cursor = Manifest::current()->cursor;

    expect(fn () => CatchUp::dispatchSync())->toThrow(CorruptBlob::class);

    expect(File::get(base_path('content/pages/about.md')))->toBe('About us')
        ->and(Manifest::current())
        ->cursor->toBe($cursor)
        ->known('content/pages/about.md')->toBe(GitHash::of('About us'))
        ->and(MailChanges::dispatchSync())->toBeNull();
});

it('remembers an edit that is already on disk', function () {
    mailedFromAnotherServer('content/pages/about.md', 'About us');
    $this->writeFile('content/pages/about.md', 'About us');

    CatchUp::dispatchSync();

    expect(Manifest::current()->known('content/pages/about.md'))->toBe(GitHash::of('About us'))
        ->and(MailChanges::dispatchSync())->toBeNull();
});

it('applies what other servers mailed before this server pushes its own edit', function () {
    mailedFromAnotherServer('content/pages/about.md', 'About us');
    $this->writeFile('content/pages/contact.md', 'Contact us');

    $batch = MailChanges::dispatchSync();

    expect($batch->changes->pluck('path')->all())->toBe(['content/pages/contact.md'])
        ->and(File::get(base_path('content/pages/about.md')))->toBe('About us')
        ->and(CatchUp::dispatchSync())->toBeNull();
});

it('never brings back a file this server removed after pushing it', function () {
    $this->writeFile('content/pages/contact.md', 'Contact us');
    MailChanges::dispatchSync();

    File::delete(base_path('content/pages/contact.md'));

    expect(CatchUp::dispatchSync())->toBeNull()
        ->and(File::exists(base_path('content/pages/contact.md')))->toBeFalse();
});

it('reads the baseline only after another catch-up on this server lets go of it', function () {
    $batch = mailedFromAnotherServer('content/pages/about.md', 'About us');
    $lock = storage_path('framework/file-boomerang.lock');
    $baseline = config('file-boomerang.manifest');
    $caughtUp = storage_path('framework/caught-up.json');
    (new Manifest($batch->id, Manifest::current()->files))->save();
    File::move($baseline, $caughtUp);
    Manifest::seed()->save();

    $holder = Process::start([PHP_BINARY, '-r', <<<PHP
        \$handle = fopen('{$lock}', 'c');
        flock(\$handle, LOCK_EX);
        echo 'locked';
        usleep(300000);
        rename('{$caughtUp}', '{$baseline}');
        flock(\$handle, LOCK_UN);
        PHP]);

    $holder->waitUntil(fn (string $type, string $output) => str_contains($output, 'locked'));

    expect(CatchUp::dispatchSync())->toBeNull()
        ->and(File::exists(base_path('content/pages/about.md')))->toBeFalse();

    $holder->wait();
});
