<?php

namespace Ahinkle\FileBoomerang;

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
        $stream = fopen($absolutePath, 'rb');

        try {
            hash_update($context, static::header(fstat($stream)['size']));
            hash_update_stream($context, $stream);
        } finally {
            fclose($stream);
        }

        return hash_final($context);
    }

    public static function isValid(mixed $hash): bool
    {
        return is_string($hash) && preg_match('/^[0-9a-f]{40}$/', $hash) === 1;
    }

    protected static function header(int $size): string
    {
        return "blob {$size}\0";
    }
}
