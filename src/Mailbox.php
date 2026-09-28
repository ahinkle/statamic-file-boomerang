<?php

namespace Ahinkle\FileBoomerang;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class Mailbox
{
    public static function disk(): Filesystem
    {
        return once(fn () => Storage::build([
            ...Arr::except(static::config(), 'visibility'),
            'throw' => true,
        ]));
    }

    public static function path(string ...$segments): string
    {
        return collect([config('file-boomerang.mailbox.prefix'), ...$segments])
            ->map(fn (?string $segment) => trim((string) $segment, '/'))
            ->filter(fn (string $segment) => $segment !== '')
            ->implode('/');
    }

    /**
     * @return array<string, mixed>
     */
    protected static function config(): array
    {
        if ($disk = config('file-boomerang.mailbox.disk')) {
            return config("filesystems.disks.{$disk}") ?? throw new InvalidArgumentException(
                "The mailbox disk [{$disk}] is not defined in config/filesystems.php."
            );
        }

        return [
            'driver' => 's3',
            'bucket' => config('file-boomerang.mailbox.bucket'),
            'endpoint' => config('file-boomerang.mailbox.endpoint'),
            'key' => config('file-boomerang.mailbox.key'),
            'secret' => config('file-boomerang.mailbox.secret'),
            'region' => config('file-boomerang.mailbox.region'),
            'use_path_style_endpoint' => config('file-boomerang.mailbox.use_path_style_endpoint'),
        ];
    }
}
