<?php

use Ahinkle\FileBoomerang\Mailbox;
use League\Flysystem\UnableToReadFile;

it('keeps every key under the prefix', function () {
    config(['file-boomerang.mailbox.prefix' => '/sites/acme/']);

    expect(Mailbox::path('batches', '01J8.json'))->toBe('sites/acme/batches/01J8.json')
        ->and(Mailbox::path())->toBe('sites/acme');
});

it('works without a prefix', function () {
    config(['file-boomerang.mailbox.prefix' => null]);

    expect(Mailbox::path('blobs', 'abc'))->toBe('blobs/abc');
});

it('throws instead of quietly returning nothing when storage fails', function () {
    config(['filesystems.disks.file-boomerang-mailbox.throw' => false]);

    expect(fn () => Mailbox::disk()->get('missing.json'))->toThrow(UnableToReadFile::class);
});

it('refuses a mailbox disk that does not exist', function () {
    config(['file-boomerang.mailbox.disk' => 'nowhere']);

    Mailbox::disk();
})->throws(InvalidArgumentException::class, 'The mailbox disk [nowhere] is not defined');

it('says which setting is missing when there is no bucket', function () {
    config(['file-boomerang.mailbox.disk' => null, 'file-boomerang.mailbox.bucket' => null]);

    Mailbox::disk();
})->throws(InvalidArgumentException::class, 'The mailbox has no bucket. Set FILE_BOOMERANG_BUCKET');
