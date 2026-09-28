<?php

namespace Ahinkle\FileBoomerang;

interface Tree
{
    public function hash(string $path): ?string;

    public function contents(string $path): ?string;

    public function base(string $hash): ?string;

    public function put(string $path, string $blob): void;

    public function write(string $path, string $contents): void;

    public function delete(string $path): void;
}
