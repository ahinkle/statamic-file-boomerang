<?php

namespace Ahinkle\FileBoomerang;

use Illuminate\Support\Facades\File;

class GitHash
{
    public static function of(string $bytes): string
    {
        $context = hash_init('sha1');

        hash_update($context, static::header(strlen($bytes)));
        hash_update($context, $bytes);

        return hash_final($context);
    }

    public static function ofFile(string $absolutePath): string
    {
        $context = hash_init('sha1');

        hash_update($context, static::header(File::size($absolutePath)));
        hash_update_file($context, $absolutePath);

        return hash_final($context);
    }

    /**
     * @phpstan-assert-if-true string $hash
     */
    public static function isValid(mixed $hash): bool
    {
        return is_string($hash) && preg_match('/^[0-9a-f]{40}$/', $hash) === 1;
    }

    protected static function header(int $size): string
    {
        return "blob {$size}\0";
    }
}
