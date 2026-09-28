<?php

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Change;
use Ahinkle\FileBoomerang\Exceptions\LandingRejected;
use Ahinkle\FileBoomerang\Jobs\RequestLanding;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function mailBatch(): Batch
{
    return Batch::record(collect([
        Change::put('content/pages/home.md', test()->storeBlob('Welcome'), null, 7),
    ]), null);
}

function requestLanding(): RequestLanding
{
    return tap((new RequestLanding)->withFakeQueueInteractions(), fn (RequestLanding $job) => $job->handle());
}

beforeEach(function () {
    config([
        'file-boomerang.debounce' => 120,
        'file-boomerang.github.repository' => 'ahinkle/sccc.org',
        'file-boomerang.github.token' => 'github_pat_owner',
    ]);

    Http::preventStrayRequests();
});

it('waits until the editors have been quiet for the debounce', function () {
    config(['queue.default' => 'database']);
    Queue::fake();
    Http::fake();
    $this->freezeSecond();
    $batch = mailBatch();

    $this->travel(60)->seconds();
    requestLanding();

    Http::assertNothingSent();
    Queue::assertPushed(RequestLanding::class, fn (RequestLanding $job) => $job->delay->equalTo($batch->createdAt()->addSeconds(120)));
});

it('asks github once to land every waiting batch', function () {
    Http::fake(['api.github.com/*' => Http::response(status: 204)]);
    mailBatch();
    $newest = mailBatch();

    $this->travel(121)->seconds();
    requestLanding();
    requestLanding();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/repos/ahinkle/sccc.org/actions/workflows/file-boomerang.yml/dispatches'
        && $request->data() === ['ref' => 'main']);
    expect($this->mailbox()->json('file-boomerang/state/requested.json'))->newest->toBe($newest->id);
});

it('asks again when a newer batch arrives or the last request went unanswered', function () {
    Http::fake(['api.github.com/*' => Http::response(status: 204)]);
    mailBatch();
    $this->travel(121)->seconds();
    requestLanding();

    $this->travel(31)->minutes();
    requestLanding();
    mailBatch();
    $this->travel(121)->seconds();
    requestLanding();

    Http::assertSentCount(3);
});

it('does nothing when the mailbox is empty', function () {
    Http::fake();

    requestLanding()->assertNotFailed();

    Http::assertNothingSent();
});

it('fails at once when github will never accept the request', function (int $status, string $reason) {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], $status)]);
    mailBatch();
    $this->travel(121)->seconds();

    requestLanding()->assertFailedWith(new LandingRejected($reason));
})->with([
    'a bad token' => [401, 'GitHub did not accept FILE_BOOMERANG_GITHUB_TOKEN. It is missing, expired or revoked, so create a new fine-grained token.'],
    'a token that may not start workflows' => [403, 'The GitHub token may not start workflows in ahinkle/sccc.org. Give it the Actions: Read and write permission.'],
    'a workflow the token cannot find' => [404, 'GitHub cannot find .github/workflows/file-boomerang.yml in ahinkle/sccc.org. Check FILE_BOOMERANG_GITHUB_REPOSITORY, that the token has access to that repository, and that the workflow is on the default branch.'],
]);

it('fails at once without a token', function () {
    config(['file-boomerang.github.token' => null]);
    Http::fake();
    mailBatch();
    $this->travel(121)->seconds();

    requestLanding()->assertFailedWith(LandingRejected::class);

    Http::assertNothingSent();
});

it('lets the queue retry when github is having a bad day', function (array $response) {
    Http::fake(['api.github.com/*' => Http::response(...$response)]);
    mailBatch();
    $this->travel(121)->seconds();
    $job = (new RequestLanding)->withFakeQueueInteractions();

    expect(fn () => $job->handle())->toThrow(RequestException::class);

    $job->assertNotFailed();
    expect($this->mailbox()->exists('file-boomerang/state/requested.json'))->toBeFalse();
})->with([
    'a server error' => [['body' => '', 'status' => 502]],
    'too many requests' => [['body' => '', 'status' => 429]],
    'a secondary rate limit' => [['body' => '', 'status' => 403, 'headers' => ['Retry-After' => '60']]],
]);
