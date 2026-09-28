<?php

namespace Ahinkle\FileBoomerang;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $config = true;

    public function bootAddon(): void
    {
        Http::macro('github', function (): PendingRequest {
            $token = Env::get('GITHUB_ACTIONS')
                ? Env::get('GITHUB_TOKEN')
                : config('file-boomerang.github.token');

            return Http::baseUrl('https://api.github.com')
                ->withToken((string) $token)
                ->accept('application/vnd.github+json')
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->connectTimeout(10)
                ->timeout(30);
        });
    }
}
