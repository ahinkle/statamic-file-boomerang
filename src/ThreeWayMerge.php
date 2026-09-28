<?php

namespace Ahinkle\FileBoomerang;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class ThreeWayMerge
{
    public function __invoke(string $ours, string $base, string $theirs): ?string
    {
        if (collect([$ours, $base, $theirs])->contains(fn (string $bytes) => $this->isBinary($bytes))) {
            return null;
        }

        $files = collect(compact('ours', 'base', 'theirs'))->map(fn (string $bytes) => $this->temporary($bytes));

        try {
            $result = Process::run(['git', 'merge-file', '-p', $files['ours'], $files['base'], $files['theirs']]);
        } finally {
            File::delete($files->all());
        }

        return $result->successful() ? $result->output() : null;
    }

    protected function isBinary(string $bytes): bool
    {
        return str_contains(substr($bytes, 0, 8000), "\0");
    }

    protected function temporary(string $bytes): string
    {
        return tap(tempnam(sys_get_temp_dir(), 'file-boomerang-merge-'), fn (string $path) => File::put($path, $bytes));
    }
}
