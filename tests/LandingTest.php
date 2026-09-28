<?php

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Blob;
use Ahinkle\FileBoomerang\Editor;
use Ahinkle\FileBoomerang\Exceptions\CorruptBlob;
use Ahinkle\FileBoomerang\Exceptions\LandingRejected;
use Ahinkle\FileBoomerang\GitHash;
use Ahinkle\FileBoomerang\Landing;
use Ahinkle\FileBoomerang\LandingResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

function anotherClone(string ...$arguments): string
{
    $clone = dirname(base_path()).'/another-clone';

    if (! is_dir($clone)) {
        Process::run(['git', 'clone', '--quiet', remote(), $clone])->throw();
    }

    return Process::path($clone)
        ->env([
            'GIT_AUTHOR_NAME' => 'Developer',
            'GIT_AUTHOR_EMAIL' => 'developer@example.com',
            'GIT_COMMITTER_NAME' => 'Developer',
            'GIT_COMMITTER_EMAIL' => 'developer@example.com',
        ])
        ->run(['git', ...$arguments])
        ->throw()
        ->output();
}

function commitInAnotherClone(string $path, string $contents): void
{
    anotherClone('pull', '--quiet', 'origin', 'main');

    File::ensureDirectoryExists(dirname(dirname(base_path())."/another-clone/{$path}"));
    File::put(dirname(base_path())."/another-clone/{$path}", $contents);

    anotherClone('add', '--', $path);
    anotherClone('commit', '--quiet', '-m', 'Developer commit');
}

function pushFromAnotherClone(string $path, string $contents): void
{
    commitInAnotherClone($path, $contents);

    anotherClone('push', '--quiet', 'origin', 'main');
}

function hook(string $path, string $script): void
{
    File::put($path, "#!/bin/sh\n{$script}\n");

    chmod($path, 0755);
}

function landing(): LandingResult
{
    return Landing::make()->onto('main')->land();
}

function batchesInMailbox(): array
{
    return test()->mailbox()->files('file-boomerang/batches');
}

$home = "title: Welcome\nsummary: Our church\nbody: Sunday at 9\n";
$andy = new Editor('Andy Hinkle', 'andy@example.com');
$greg = new Editor('Greg Davis', 'greg@example.com');

beforeEach(function () use ($home) {
    config(['file-boomerang.github.repository' => 'ahinkle/sccc.org']);

    Http::preventStrayRequests();

    $this->writeFile('content/pages/home.md', $home);

    pushToRemote();
});

afterEach(function () {
    unset($_SERVER['GITHUB_OUTPUT'], $_ENV['GITHUB_OUTPUT'], $_SERVER['GITHUB_STEP_SUMMARY'], $_ENV['GITHUB_STEP_SUMMARY']);
});

it('lands an edit as a commit by the editor and empties the mailbox', function () use ($home, $andy) {
    mailed(edit('content/pages/home.md', "title: Welcome home\nsummary: Our church\nbody: Sunday at 9\n", from: $home), $andy);

    $result = landing();

    expect(fileOnRemote('content/pages/home.md'))->toBe("title: Welcome home\nsummary: Our church\nbody: Sunday at 9\n")
        ->and(onRemote('log', '-1', '--format=%an <%ae>%n%cn <%ce>%n%B', 'main'))->toBe(implode("\n", [
            'Andy Hinkle <andy@example.com>',
            'github-actions[bot] <41898282+github-actions[bot]@users.noreply.github.com>',
            'Update content from the control panel',
            '',
            'content/pages/home.md',
        ]))
        ->and($result->sha)->toBe(onRemote('rev-parse', 'main'))
        ->and($result->summary())->toStartWith('Landed 1 file from 1 batch in ')
        ->and(batchesInMailbox())->toBeEmpty();
});

it('adds new files and deletes removed ones', function () use ($home) {
    mailed(edit('content/pages/about.md', 'About us'));
    mailed(removal('content/pages/home.md', from: $home));

    landing();

    expect(fileOnRemote('content/pages/about.md'))->toBe('About us')
        ->and(fileOnRemote('content/pages/home.md'))->toBeNull();
});

it('merges an edit with a developer commit that touched other lines', function () use ($home) {
    pushFromAnotherClone('content/pages/home.md', "title: Welcome home\nsummary: Our church\nbody: Sunday at 9\n");
    mailed(edit('content/pages/home.md', "title: Welcome\nsummary: Our church\nbody: Sunday at 10\n", from: $home));

    landing();

    expect(fileOnRemote('content/pages/home.md'))->toBe("title: Welcome home\nsummary: Our church\nbody: Sunday at 10\n")
        ->and(onRemote('log', '--format=%s', 'main'))->toBe("Update content from the control panel\nDeveloper commit\nInitial commit");
});

it('credits the newest editor and adds the others as co-authors', function () use ($andy, $greg) {
    mailed(edit('content/pages/one.md', 'One'), $andy);
    mailed(edit('content/pages/two.md', 'Two'), $greg);

    landing();

    expect(onRemote('log', '-1', '--format=%an%n%B', 'main'))->toBe(implode("\n", [
        'Greg Davis',
        'Update content from the control panel',
        '',
        'content/pages/one.md',
        'content/pages/two.md',
        '',
        'Co-authored-by: Andy Hinkle <andy@example.com>',
    ]));
});

it('credits the configured author when the newest change has no editor', function () use ($andy) {
    mailed(edit('content/pages/one.md', 'One'), $andy);
    mailed(edit('content/pages/two.md', 'Two'));

    landing();

    expect(onRemote('log', '-1', '--format=%an <%ae>', 'main'))->toBe('Statamic <statamic@users.noreply.github.com>')
        ->and(onRemote('log', '-1', '--format=%(trailers:key=Co-authored-by,valueonly)', 'main'))->toBe('Andy Hinkle <andy@example.com>');
});

it('lists at most thirty paths in the commit message', function () {
    Batch::record(collect(range(1, 32))->map(fn (int $number) => edit(sprintf('content/pages/%02d.md', $number), "Page {$number}")), null);

    landing();

    expect(onRemote('log', '-1', '--format=%b', 'main'))->toEndWith("content/pages/30.md\nand 2 more");
});

it('retries when a concurrent commit rejects the push', function () use ($home) {
    commitInAnotherClone('content/pages/home.md', "title: Welcome home\nsummary: Our church\nbody: Sunday at 9\n");
    hook(base_path('.git/hooks/pre-push'), implode("\n", [
        '[ -e "$0.ran" ] && exit 0',
        'touch "$0.ran"',
        'unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE',
        'git -C '.escapeshellarg(dirname(base_path()).'/another-clone').' push --quiet origin main',
    ]));
    mailed(edit('content/pages/home.md', "title: Welcome\nsummary: Our church\nbody: Sunday at 10\n", from: $home));

    landing();

    expect(fileOnRemote('content/pages/home.md'))->toBe("title: Welcome home\nsummary: Our church\nbody: Sunday at 10\n")
        ->and(onRemote('log', '--format=%s', 'main'))->toBe("Update content from the control panel\nDeveloper commit\nInitial commit")
        ->and(batchesInMailbox())->toBeEmpty();
});

it('gives up and keeps every batch when the push keeps failing', function () use ($home) {
    hook(remote().'/hooks/pre-receive', 'exit 1');
    mailed(edit('content/pages/home.md', "title: Welcome home\nsummary: Our church\nbody: Sunday at 9\n", from: $home));
    $initial = onRemote('rev-parse', 'main');

    expect(fn () => landing())->toThrow(LandingRejected::class);

    expect(onRemote('rev-parse', 'main'))->toBe($initial)
        ->and(batchesInMailbox())->toHaveCount(1);
});

it('opens a pull request for edits that conflict with the branch', function () use ($home, $andy) {
    Http::fake(['api.github.com/repos/ahinkle/sccc.org/pulls' => Http::response(['html_url' => 'https://github.com/ahinkle/sccc.org/pull/7'], 201)]);
    pushFromAnotherClone('content/pages/home.md', "title: Welcome\nsummary: Our church\nbody: Sunday at 11\n");
    $batch = mailed(edit('content/pages/home.md', "title: Welcome\nsummary: Our church\nbody: Sunday at 10\n", from: $home), $andy);

    $result = landing();

    expect(fileOnRemote('content/pages/home.md'))->toBe("title: Welcome\nsummary: Our church\nbody: Sunday at 11\n")
        ->and(fileOnRemote('content/pages/home.md', "file-boomerang/conflict-{$batch->id}"))->toBe("title: Welcome\nsummary: Our church\nbody: Sunday at 10\n")
        ->and(onRemote('log', '-1', '--format=%an %s', "file-boomerang/conflict-{$batch->id}"))->toBe('Andy Hinkle Control panel edits that conflict with main')
        ->and($result->conflictsUrl)->toBe('https://github.com/ahinkle/sccc.org/pull/7')
        ->and(batchesInMailbox())->toBeEmpty();

    Http::assertSent(fn (Request $request) => $request['title'] === 'Control panel edits that conflict with main'
        && $request['head'] === "file-boomerang/conflict-{$batch->id}"
        && $request['base'] === 'main'
        && str_contains($request['body'], "| `content/pages/home.md` | Andy Hinkle | {$batch->id} |"));
});

it('opens an issue when github actions may not open pull requests', function () use ($home) {
    Http::fake([
        'api.github.com/repos/ahinkle/sccc.org/pulls' => Http::response(['message' => 'GitHub Actions is not permitted to create or approve pull requests.'], 403),
        'api.github.com/repos/ahinkle/sccc.org/issues' => Http::response(['html_url' => 'https://github.com/ahinkle/sccc.org/issues/8'], 201),
    ]);
    pushFromAnotherClone('content/pages/home.md', "title: Welcome\nsummary: Our church\nbody: Sunday at 11\n");
    $batch = mailed(removal('content/pages/home.md', from: $home));

    $result = landing();

    expect($result->conflictsUrl)->toBe('https://github.com/ahinkle/sccc.org/issues/8')
        ->and(fileOnRemote('content/pages/home.md', "file-boomerang/conflict-{$batch->id}"))->toBeNull()
        ->and(batchesInMailbox())->toBeEmpty();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/issues')
        && str_contains($request['body'], "https://github.com/ahinkle/sccc.org/compare/main...file-boomerang/conflict-{$batch->id}?expand=1")
        && str_contains($request['body'], '| `content/pages/home.md` (deleted) | System |'));
});

it('keeps every batch when github refuses both a pull request and an issue', function () use ($home) {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Resource not accessible by integration'], 403)]);
    pushFromAnotherClone('content/pages/home.md', "title: Welcome\nsummary: Our church\nbody: Sunday at 11\n");
    mailed(edit('content/pages/home.md', "title: Welcome\nsummary: Our church\nbody: Sunday at 10\n", from: $home));

    expect(fn () => landing())->toThrow(LandingRejected::class);

    expect(batchesInMailbox())->toHaveCount(1);
});

it('deletes only the batches it landed', function () use ($home) {
    Http::fake(function () {
        mailed(edit('content/pages/late.md', 'Saved while landing'));

        return Http::response(['html_url' => 'https://github.com/ahinkle/sccc.org/pull/7'], 201);
    });
    pushFromAnotherClone('content/pages/home.md', "title: Welcome\nsummary: Our church\nbody: Sunday at 11\n");
    mailed(edit('content/pages/home.md', "title: Welcome\nsummary: Our church\nbody: Sunday at 10\n", from: $home));

    landing();

    expect(batchesInMailbox())->toHaveCount(1)
        ->and(Blob::exists(GitHash::of('Saved while landing')))->toBeTrue();
});

it('prunes landed blobs once they are past the grace period', function () use ($home) {
    mailed(edit('content/pages/home.md', "title: Welcome home\nsummary: Our church\nbody: Sunday at 9\n", from: $home));
    collect($this->mailbox()->files('file-boomerang/blobs'))->each(fn (string $blob) => touch(
        $this->mailbox()->path($blob), now()->subHours(2)->getTimestamp()
    ));
    $fresh = $this->storeBlob('Pushed a moment ago, batch not written yet');

    landing();

    expect($this->mailbox()->files('file-boomerang/blobs'))->toBe(["file-boomerang/blobs/{$fresh}"]);
});

it('skips unsafe and untracked paths and lands the rest', function () {
    mailed(edit('content/../escape.md', 'nope'));
    mailed(edit('.git/hooks/pre-commit', 'nope'));
    mailed(edit('app/Models/User.php', 'nope'));
    mailed(edit('content/pages/about.md', 'About us'));

    $result = landing();

    expect($result->skipped->keys()->all())->toBe(['content/../escape.md', '.git/hooks/pre-commit', 'app/Models/User.php'])
        ->and(fileOnRemote('content/pages/about.md'))->toBe('About us')
        ->and(fileOnRemote('app/Models/User.php'))->toBeNull()
        ->and(File::exists(base_path('escape.md')))->toBeFalse()
        ->and(File::exists(base_path('.git/hooks/pre-commit')))->toBeFalse()
        ->and(batchesInMailbox())->toBeEmpty();
});

it('keeps every batch when a blob is corrupt', function () use ($home) {
    mailed(edit('content/pages/about.md', 'About us'));
    $batch = mailed(edit('content/pages/home.md', "title: Welcome home\nsummary: Our church\nbody: Sunday at 9\n", from: $home));
    $this->mailbox()->put("file-boomerang/blobs/{$batch->changes->first()->blob}", 'Tampered');
    $initial = onRemote('rev-parse', 'main');

    expect(fn () => landing())->toThrow(CorruptBlob::class);

    expect(onRemote('rev-parse', 'main'))->toBe($initial)
        ->and(batchesInMailbox())->toHaveCount(2);
});

it('writes nothing anywhere on a dry run', function () use ($home) {
    pushFromAnotherClone('content/pages/home.md', "title: Welcome\nsummary: Our church\nbody: Sunday at 11\n");
    $this->git('pull', '--quiet');
    mailed(edit('content/pages/home.md', "title: Welcome\nsummary: Our church\nbody: Sunday at 10\n", from: $home));
    mailed(edit('content/pages/about.md', 'About us'));
    $mailbox = $this->mailbox()->allFiles();
    $initial = onRemote('rev-parse', 'main');

    $result = Landing::make()->onto('main')->dryRun();

    expect($result->paths->all())->toBe(['content/pages/about.md'])
        ->and($result->conflicts->keys()->all())->toBe(['content/pages/home.md'])
        ->and($result->summary())->toBe('Would land 1 file from 2 batches.')
        ->and(onRemote('rev-parse', 'main'))->toBe($initial)
        ->and(onRemote('branch', '--list', 'file-boomerang/*'))->toBe('')
        ->and($this->git('status', '--porcelain'))->toBe('')
        ->and($this->mailbox()->allFiles())->toBe($mailbox);
});

it('refuses to land from a checkout that is not ready', function (Closure $scenario, string $message) {
    $scenario();
    mailed(edit('content/pages/about.md', 'About us'));

    expect(fn () => landing())->toThrow(LandingRejected::class, $message);

    expect(batchesInMailbox())->toHaveCount(1);
})->with([
    'uncommitted changes' => [fn () => test()->writeFile('content/pages/draft.md', 'Draft'), 'The checkout has uncommitted changes.'],
    'another branch' => [fn () => test()->git('switch', '--quiet', '--create', 'feature'), 'The checkout is not on the [main] branch.'],
    'unpushed commits' => [fn () => test()->git('commit', '--quiet', '--allow-empty', '-m', 'Local work'), 'The checkout has commits that are not on origin/main.'],
]);

it('lands nothing when the mailbox is empty', function () {
    config(['file-boomerang.landing.deploy_hook' => 'https://cloud.example.com/deploy']);
    $_SERVER['GITHUB_OUTPUT'] = $_ENV['GITHUB_OUTPUT'] = dirname(base_path()).'/github-output';

    $result = landing();

    expect($result->summary())->toBe('Nothing is waiting in the mailbox.')
        ->and(onRemote('rev-list', '--count', 'main'))->toBe('1')
        ->and(File::get(dirname(base_path()).'/github-output'))->toBe("landed=false\nsha={$result->sha}\nconflicts=0\n");
});

it('deploys, runs the other workflows and reports to github actions after landing', function () {
    config([
        'file-boomerang.landing.deploy_hook' => 'https://cloud.example.com/deploy?commit_hash={sha}',
        'file-boomerang.landing.workflows' => ['tests.yml'],
    ]);
    $_SERVER['GITHUB_OUTPUT'] = $_ENV['GITHUB_OUTPUT'] = dirname(base_path()).'/github-output';
    $_SERVER['GITHUB_STEP_SUMMARY'] = $_ENV['GITHUB_STEP_SUMMARY'] = dirname(base_path()).'/github-summary';
    Http::fake();
    mailed(edit('content/pages/about.md', 'About us'));

    $result = landing();

    Http::assertSent(fn (Request $request) => $request->url() === "https://cloud.example.com/deploy?commit_hash={$result->sha}");
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/repos/ahinkle/sccc.org/actions/workflows/tests.yml/dispatches'
        && $request['ref'] === 'main');

    expect(File::get(dirname(base_path()).'/github-output'))->toBe("landed=true\nsha={$result->sha}\nconflicts=0\n")
        ->and(File::get(dirname(base_path()).'/github-summary'))->toContain($result->summary(), '| `content/pages/about.md` | landed |');
});

it('prints what landed', function () {
    mailed(edit('content/pages/about.md', 'About us'));

    $this->artisan('boomerang:land', ['--dry-run' => true])
        ->expectsOutputToContain('content/pages/about.md')
        ->expectsOutputToContain('Would land 1 file from 1 batch.')
        ->assertSuccessful();
});

it('fails with the reason when landing is refused', function () {
    $this->writeFile('content/pages/draft.md', 'Draft');

    $this->artisan('boomerang:land')
        ->expectsOutputToContain('The checkout has uncommitted changes.')
        ->assertFailed();
});
