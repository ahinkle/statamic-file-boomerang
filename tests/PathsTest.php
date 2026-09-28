<?php

use Ahinkle\FileBoomerang\Exceptions\UnsafePath;
use Ahinkle\FileBoomerang\Paths;
use Statamic\Facades\AssetContainer;

it('allows files inside the tracked paths', function (string $path) {
    expect(Paths::assertSafe($path))->toBe($path);
})->with([
    'an entry' => 'content/collections/pages/home.md',
    'dots inside a name' => 'content/a..b.md',
    'a tracked file' => 'resources/sites.yaml',
    'a form submission' => 'storage/forms/contact/1727460000.yaml',
]);

it('refuses paths that could escape or are not tracked', function (string $path) {
    expect(fn () => Paths::assertSafe($path))->toThrow(UnsafePath::class)
        ->and(Paths::allows($path))->toBeFalse();
})->with([
    'a parent segment' => 'content/../x',
    'a current segment' => 'content/./x.md',
    'git internals' => '.git/config',
    'a nested git directory' => 'content/.git/config',
    'an absolute path' => '/etc/passwd',
    'a drive letter' => 'C:/Windows/win.ini',
    'a backslash' => 'content\\x.md',
    'an empty segment' => 'content//x.md',
    'empty' => '',
    'a newline' => "content/x\n.md",
    'an untracked directory' => 'resources/other/x',
    'a sibling that shares a prefix' => 'contents/x.md',
    'an excluded file' => 'content/pages/.DS_Store',
    'a partial download' => 'content/pages/.file-boomerang-a1b2c3',
]);

it('tracks the roots of local asset containers inside the project', function () {
    config([
        'filesystems.disks.images' => ['driver' => 'local', 'root' => public_path('img')],
        'filesystems.disks.media' => ['driver' => 'local', 'root' => public_path('media').'/'],
        'filesystems.disks.cloud' => ['driver' => 's3', 'bucket' => 'assets'],
        'filesystems.disks.elsewhere' => ['driver' => 'local', 'root' => dirname(base_path()).'/shared'],
        'filesystems.disks.everything' => ['driver' => 'local', 'root' => base_path()],
    ]);

    collect(['images', 'media', 'cloud', 'elsewhere', 'everything'])
        ->each(fn (string $disk) => AssetContainer::make($disk)->disk($disk)->save());

    expect(Paths::tracked())
        ->toContain('public/img', 'public/media', 'content')
        ->not->toContain('', 'shared')
        ->and(Paths::allows('public/img/staff/greg.jpg'))->toBeTrue()
        ->and(Paths::allows('public/img/.meta/staff/greg.jpg.yaml'))->toBeTrue();
});

it('leaves asset containers alone when that is turned off', function () {
    config([
        'file-boomerang.local_asset_containers' => false,
        'filesystems.disks.images' => ['driver' => 'local', 'root' => public_path('img')],
    ]);

    AssetContainer::make('images')->disk('images')->save();

    expect(Paths::allows('public/img/logo.png'))->toBeFalse();
});

it('collapses tracked paths nested inside another', function () {
    config(['file-boomerang.paths' => ['content', 'content/collections', 'public', 'resources/sites.yaml']]);

    expect(Paths::tracked()->all())->toBe(['content', 'public', 'resources/sites.yaml']);
});

it('never tracks the glide cache when it is served from the project', function () {
    config([
        'file-boomerang.paths' => ['content', 'public'],
        'statamic.assets.image_manipulation.cache' => true,
        'statamic.assets.image_manipulation.cache_path' => public_path('glide'),
    ]);

    expect(Paths::allows('public/glide/containers/images/logo.png/abc.png'))->toBeFalse()
        ->and(Paths::allows('public/logo.png'))->toBeTrue();
});

it('keeps an asset container that shares the default glide cache path while the cache is off', function () {
    config([
        'statamic.assets.image_manipulation.cache' => false,
        'statamic.assets.image_manipulation.cache_path' => public_path('img'),
        'filesystems.disks.images' => ['driver' => 'local', 'root' => public_path('img')],
    ]);

    AssetContainer::make('images')->disk('images')->save();

    expect(Paths::allows('public/img/logo.png'))->toBeTrue();
});

it('walks tracked files and skips symlinks, excluded files and missing paths', function () {
    config(['file-boomerang.paths' => ['content', 'resources/sites.yaml', 'resources/missing']]);

    $this->writeFile('content/pages/home.md', 'home');
    $this->writeFile('content/pages/.DS_Store', 'finder');
    $this->writeFile('resources/sites.yaml', 'sites');
    $this->writeFile('outside.md', 'secret');
    symlink(base_path('outside.md'), base_path('content/pages/linked.md'));

    expect(Paths::files()->keys()->sort()->values()->all())->toBe([
        'content/pages/home.md',
        'resources/sites.yaml',
    ]);
});

it('lists files over the size limit', function () {
    config(['file-boomerang.max_file_size' => 10]);

    $this->writeFile('content/small.md', 'small');
    $this->writeFile('content/large.mp4', str_repeat('x', 11));

    expect(Paths::oversized()->keys()->all())->toBe(['content/large.mp4']);
});
