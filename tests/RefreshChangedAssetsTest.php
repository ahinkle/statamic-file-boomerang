<?php

use Illuminate\Support\Facades\File;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blink;

function assetPaths(): array
{
    return AssetContainer::find('images')->queryAssets()->get()->map->path()->sort()->values()->all();
}

function refreshStacheOnDeploy(): void
{
    Blink::flush();
    test()->artisan('statamic:stache:refresh')->assertSuccessful();
    Blink::flush();
}

beforeEach(function () {
    config(['filesystems.disks.images' => ['driver' => 'local', 'root' => public_path('img')]]);

    $this->writeFile('public/img/logo.png', 'PNG logo');
    $this->writeFile('public/img/.meta/logo.png.yaml', "data:\n  alt: 'Our logo'\n");
    $this->writeFile('public/img/staff/greg.jpg', 'JPEG greg');
    AssetContainer::make('images')->disk('images')->save();

    config(['file-boomerang.enabled' => true]);

    expect(assetPaths())->toBe(['logo.png', 'staff/greg.jpg'])
        ->and(Asset::find('images::logo.png')->get('alt'))->toBe('Our logo');
});

it('shows the images a deploy added, renamed and removed once the stache is refreshed', function () {
    File::move(public_path('img/staff/greg.jpg'), public_path('img/staff/gregory.jpg'));
    File::delete(public_path('img/logo.png'), public_path('img/.meta/logo.png.yaml'));
    $this->writeFile('public/img/banner.jpg', 'JPEG banner');
    Blink::flush();
    expect(Asset::find('images::staff/gregory.jpg'))->toBeNull();

    refreshStacheOnDeploy();

    expect(assetPaths())->toBe(['banner.jpg', 'staff/gregory.jpg'])
        ->and(Asset::find('images::staff/greg.jpg'))->toBeNull()
        ->and(Asset::find('images::logo.png'))->toBeNull();
});

it('shows the alt text a deploy changed and the image it replaced', function () {
    $this->writeFile('public/img/staff/greg.jpg', 'JPEG greg in a new jacket');
    $this->writeFile('public/img/.meta/logo.png.yaml', "data:\n  alt: 'The site logo'\n");

    refreshStacheOnDeploy();

    expect(Asset::find('images::logo.png')->get('alt'))->toBe('The site logo')
        ->and(AssetContainer::find('images')->contents()->all()->get('staff/greg.jpg')['size'])->toBe(25);
});

it('forgets a folder a deploy emptied', function () {
    File::deleteDirectory(public_path('img/staff'));

    refreshStacheOnDeploy();

    expect(AssetContainer::find('images')->contents()->all()->keys()->all())->not->toContain('staff', 'staff/greg.jpg');
});

it('leaves the remembered file list alone when nothing on disk changed', function () {
    $remembered = AssetContainer::find('images')->contents()->all()->all();

    refreshStacheOnDeploy();

    expect(AssetContainer::find('images')->contents()->all()->all())->toBe($remembered);
});

it('leaves containers that are not on a local public disk alone', function () {
    config(['filesystems.disks.private' => ['driver' => 'local', 'root' => storage_path('app/private-assets')]]);
    $this->writeFile('storage/app/private-assets/report.pdf', 'PDF report');
    AssetContainer::make('documents')->disk('private')->save();
    expect(Asset::find('documents::report.pdf'))->not->toBeNull();

    $this->writeFile('storage/app/private-assets/minutes.pdf', 'PDF minutes');

    refreshStacheOnDeploy();

    expect(Asset::find('documents::minutes.pdf'))->toBeNull();
});

it('does nothing while file boomerang is turned off', function () {
    config(['file-boomerang.enabled' => false]);
    $this->writeFile('public/img/banner.jpg', 'JPEG banner');

    refreshStacheOnDeploy();

    expect(Asset::find('images::banner.jpg'))->toBeNull();
});
