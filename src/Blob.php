<?php

namespace Ahinkle\FileBoomerang;

use Ahinkle\FileBoomerang\Exceptions\CorruptBlob;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\File;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToReadFile;
use Throwable;

class Blob
{
    public static function store(string $absolutePath): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'file-boomerang-');

        try {
            File::copy($absolutePath, $temporary);

            return tap(GitHash::ofFile($temporary), fn (string $hash) => static::upload($hash, $temporary));
        } finally {
            File::delete($temporary);
        }
    }

    public static function exists(string $hash): bool
    {
        return Mailbox::disk()->fileExists(static::path($hash));
    }

    public static function contents(string $hash): string
    {
        $bytes = Mailbox::disk()->get(static::path($hash)) ?? throw UnableToReadFile::fromLocation(static::path($hash));

        if (($actual = GitHash::of($bytes)) !== $hash) {
            throw new CorruptBlob($hash, $actual);
        }

        return $bytes;
    }

    public static function copyTo(string $hash, string $absolutePath): void
    {
        File::ensureDirectoryExists(dirname($absolutePath));

        $temporary = static::download($hash, dirname($absolutePath));

        try {
            throw_if(($actual = GitHash::ofFile($temporary)) !== $hash, new CorruptBlob($hash, $actual));

            File::move($temporary, $absolutePath);
        } catch (Throwable $e) {
            File::delete($temporary);

            throw $e;
        }
    }

    public static function prune(Batches $remaining, CarbonInterface $olderThan): int
    {
        $referenced = $remaining->blobs()->flip();

        return collect(Mailbox::disk()->listContents(Mailbox::path('blobs'), true)->toArray())
            ->filter->isFile()
            ->reject(fn (StorageAttributes $blob) => $referenced->has(basename($blob->path())))
            ->filter(fn (StorageAttributes $blob) => $blob->lastModified() < $olderThan->getTimestamp())
            ->each(fn (StorageAttributes $blob) => Mailbox::disk()->delete($blob->path()))
            ->count();
    }

    protected static function path(string $hash): string
    {
        return Mailbox::path('blobs', $hash);
    }

    protected static function upload(string $hash, string $temporary): void
    {
        $stream = fopen($temporary, 'rb') ?: throw UnableToReadFile::fromLocation($temporary);

        try {
            Mailbox::disk()->writeStream(static::path($hash), $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    protected static function download(string $hash, string $directory): string
    {
        $stream = Mailbox::disk()->readStream(static::path($hash)) ?? throw UnableToReadFile::fromLocation(static::path($hash));

        $temporary = tempnam($directory, '.file-boomerang-');

        try {
            File::put($temporary, $stream);
        } catch (Throwable $e) {
            File::delete($temporary);

            throw $e;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        chmod($temporary, 0666 & ~umask());

        return $temporary;
    }
}
