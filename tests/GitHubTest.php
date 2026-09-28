<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function runningInActions(string $value): void
{
    $_SERVER['GITHUB_ACTIONS'] = $_ENV['GITHUB_ACTIONS'] = $value;
    $_SERVER['GITHUB_TOKEN'] = $_ENV['GITHUB_TOKEN'] = 'ghs_actions';
}

afterEach(function () {
    unset($_SERVER['GITHUB_ACTIONS'], $_ENV['GITHUB_ACTIONS'], $_SERVER['GITHUB_TOKEN'], $_ENV['GITHUB_TOKEN']);
});

it('talks to the github api with the configured token', function () {
    runningInActions('');
    config(['file-boomerang.github.token' => 'github_pat_owner']);
    Http::fake();

    Http::github()->get('repos/acme/website');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/repos/acme/website'
        && $request->hasHeader('Authorization', 'Bearer github_pat_owner'));
});

it('uses the workflow token inside github actions', function () {
    runningInActions('true');
    config(['file-boomerang.github.token' => 'github_pat_owner']);
    Http::fake();

    Http::github()->get('repos/acme/website');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer ghs_actions'));
});
