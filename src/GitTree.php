<?php

namespace Ahinkle\FileBoomerang;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class GitTree implements Tree
{
    public function __construct(protected string $root) {}

    public function hash(string $path): ?string
    {
        return File::isFile($this->path($path)) ? GitHash::ofFile($this->path($path)) : null;
    }

    public function contents(string $path): ?string
    {
        return File::isFile($this->path($path)) ? File::get($this->path($path)) : null;
    }

    public function base(string $hash): ?string
    {
        $result = Process::path($this->root)->run(['git', 'cat-file', 'blob', $hash]);

        if ($result->successful()) {
            return $result->output();
        }

        return Blob::exists($hash) ? Blob::contents($hash) : null;
    }

    public function put(string $path, string $blob): void
    {
        Blob::copyTo($blob, $this->path($path));
    }

    public function write(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($this->path($path)));

        File::replace($this->path($path), $contents);
    }

    public function delete(string $path): void
    {
        File::delete($this->path($path));
    }

    protected function path(string $path): string
    {
        return rtrim($this->root, '/')."/{$path}";
    }
}
