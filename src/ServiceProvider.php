<?php

namespace Ahinkle\FileBoomerang;

use Ahinkle\FileBoomerang\Http\Middleware\CatchUpBeforeEditing;
use Ahinkle\FileBoomerang\Listeners\RecordContentChanges;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $config = true;

    protected $subscribe = [
        RecordContentChanges::class,
    ];

    protected $middlewareGroups = [
        'statamic.cp.authenticated' => [
            CatchUpBeforeEditing::class,
        ],
    ];

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

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('boomerang:push')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->when(fn () => config('file-boomerang.enabled'));

        $schedule->command('boomerang:dispatch')
            ->everyMinute()
            ->withoutOverlapping()
            ->when(fn () => config('file-boomerang.enabled'));
    }
}
