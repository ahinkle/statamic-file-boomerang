<?php

namespace Ahinkle\FileBoomerang;

use Illuminate\Support\Facades\File;

class WorkingTree implements Tree
{
    public function __construct(protected Manifest $manifest = new Manifest) {}

    public function hash(string $path): ?string
    {
        return $this->manifest->hashOf($path);
    }

    public function contents(string $path): ?string
    {
        return File::isFile(base_path($path)) ? File::get(base_path($path)) : null;
    }

    public function base(string $hash): ?string
    {
        return Blob::exists($hash) ? Blob::contents($hash) : null;
    }

    public function put(string $path, string $blob): void
    {
        Blob::copyTo($blob, base_path($path));
    }

    public function write(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname(base_path($path)));

        File::replace(base_path($path), $contents);
    }

    public function delete(string $path): void
    {
        File::delete(base_path($path));
    }
}
