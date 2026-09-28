<?php

namespace Ahinkle\FileBoomerang\Http\Middleware;

use Ahinkle\FileBoomerang\Jobs\CatchUp;
use Ahinkle\FileBoomerang\Manifest;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response;

class CatchUpBeforeEditing
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isDue()) {
            rescue(fn () => Manifest::lock(fn () => $this->catchUp()));
        }

        return $next($request);
    }

    protected function isDue(): bool
    {
        return config('file-boomerang.enabled')
            && config('file-boomerang.catch_up.enabled')
            && User::current()
            && ! $this->caughtUpRecently();
    }

    protected function catchUp(): void
    {
        File::ensureDirectoryExists(dirname($this->stamp()));

        touch($this->stamp(), now()->getTimestamp());

        CatchUp::dispatchSync();
    }

    protected function caughtUpRecently(): bool
    {
        clearstatcache(true, $this->stamp());

        return File::exists($this->stamp())
            && File::lastModified($this->stamp()) > now()->subSeconds(config()->integer('file-boomerang.catch_up.interval'))->getTimestamp();
    }

    protected function stamp(): string
    {
        return dirname(config()->string('file-boomerang.manifest')).'/file-boomerang-caught-up';
    }
}
