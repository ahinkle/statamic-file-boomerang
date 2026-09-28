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
        return collect([static::prefix(), ...$segments])
            ->map(fn (string $segment) => trim($segment, '/'))
            ->reject(fn (string $segment) => $segment === '')
            ->implode('/');
    }

    protected static function prefix(): string
    {
        $prefix = config('file-boomerang.mailbox.prefix');

        return is_string($prefix) ? $prefix : '';
    }

    /**
     * @return array<mixed>
     */
    protected static function config(): array
    {
        $disk = config('file-boomerang.mailbox.disk');

        if (is_string($disk) && $disk !== '') {
            return static::diskConfig($disk);
        }

        throw_if(
            blank(config('file-boomerang.mailbox.bucket')),
            InvalidArgumentException::class,
            'The mailbox has no bucket. Set FILE_BOOMERANG_BUCKET, FILE_BOOMERANG_ENDPOINT, FILE_BOOMERANG_ACCESS_KEY_ID and FILE_BOOMERANG_SECRET_ACCESS_KEY.',
        );

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

    /**
     * @return array<mixed>
     */
    protected static function diskConfig(string $disk): array
    {
        $config = config("filesystems.disks.{$disk}");

        throw_unless(is_array($config), InvalidArgumentException::class, "The mailbox disk [{$disk}] is not defined in config/filesystems.php.");

        return $config;
    }
}
