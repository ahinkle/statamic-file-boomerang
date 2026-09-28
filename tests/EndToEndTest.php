<?php

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Manifest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Once;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

function checkOutInGitHubActions(): void
{
    $checkout = dirname(base_path()).'/checkout';

    Process::run(['git', 'clone', '--quiet', remote(), $checkout])->throw();

    app()->setBasePath($checkout);

    config(['filesystems.disks.images.root' => public_path('img')]);

    Once::flush();
}

beforeEach(function () {
    config(['filesystems.disks.images' => ['driver' => 'local', 'root' => public_path('img')]]);

    AssetContainer::make('images')->disk('images')->save();
    Collection::make('pages')->save();
    Entry::make()->collection('pages')->id('home')->slug('home')->data(['title' => 'Home'])->save();

    pushToRemote();

    Manifest::seed()->save();

    config([
        'file-boomerang.enabled' => true,
        'file-boomerang.github.repository' => 'acme/website',
    ]);

    Http::preventStrayRequests();
});

it('carries a control panel save and an upload into a commit by the editor', function () {
    $this->actingAs(User::make()->id('andy')->email('andy@example.com')->set('name', 'Andy Hinkle'));

    Entry::find('home')->set('title', 'Welcome home')->save();
    AssetContainer::find('images')->makeAsset('staff/greg.jpg')->upload(UploadedFile::fake()->image('greg.jpg', 40, 30));

    $uploaded = File::get(public_path('img/staff/greg.jpg'));

    expect(Batch::pending())->not->toBeEmpty();

    checkOutInGitHubActions();
    Http::fake();

    $this->artisan('boomerang:land')->assertSuccessful();

    expect(onRemote('log', '-1', '--format=%an <%ae>', 'main'))->toBe('Andy Hinkle <andy@example.com>')
        ->and(fileOnRemote('content/collections/pages/home.md'))->toContain('title: \'Welcome home\'')
        ->and(fileOnRemote('public/img/staff/greg.jpg'))->toBe($uploaded)
        ->and(fileOnRemote('public/img/staff/.meta/greg.jpg.yaml'))->toContain('width: 40')
        ->and($this->mailbox()->files('file-boomerang/batches'))->toBeEmpty();

    Http::assertNothingSent();
});
