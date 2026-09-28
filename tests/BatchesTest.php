<?php

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Batches;
use Ahinkle\FileBoomerang\Change;
use Ahinkle\FileBoomerang\Divergence;
use Ahinkle\FileBoomerang\Editor;
use Ahinkle\FileBoomerang\GitHash;
use Ahinkle\FileBoomerang\GitTree;
use Ahinkle\FileBoomerang\Outcome;
use Ahinkle\FileBoomerang\WorkingTree;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

function land(Divergence $divergence = Divergence::Merge): Outcome
{
    return tap(Batch::pending()->applyTo(new WorkingTree, $divergence))->writeTo(new WorkingTree);
}

function contents(string $path): ?string
{
    return File::exists(base_path($path)) ? File::get(base_path($path)) : null;
}

$andy = new Editor('Andy Hinkle', 'andy@example.com');
$greg = new Editor('Greg Davis', 'greg@example.com');

it('fast-forwards an edit made from the current version', function (Divergence $divergence) {
    $this->writeFile('content/home.md', 'Welcome');
    mailed(edit('content/home.md', 'Welcome home', from: 'Welcome'));

    $outcome = land($divergence);

    expect(contents('content/home.md'))->toBe('Welcome home')
        ->and($outcome->paths()->all())->toBe(['content/home.md'])
        ->and($outcome->conflicts)->toBeEmpty();
})->with(Divergence::cases());

it('adds a file that is new everywhere', function () {
    mailed(edit('content/events/picnic.md', 'Picnic'));

    land();

    expect(contents('content/events/picnic.md'))->toBe('Picnic');
});

it('does nothing when the tree already has the edit', function () {
    $this->writeFile('content/home.md', 'Welcome home');
    mailed(edit('content/home.md', 'Welcome home', from: 'Welcome'));

    $outcome = land();

    expect($outcome->paths())->toBeEmpty()
        ->and($outcome->skipped)->toBeEmpty()
        ->and($outcome->conflicts)->toBeEmpty();
});

it('follows a chain of edits across batches', function () {
    $this->writeFile('content/home.md', 'one');
    mailed(edit('content/home.md', 'two', from: 'one'));
    mailed(edit('content/home.md', 'three', from: 'two'));

    $outcome = land();

    expect(contents('content/home.md'))->toBe('three')
        ->and($outcome->batchIds)->toHaveCount(2);
});

it('deletes a file that has not changed since the editor saw it', function () {
    $this->writeFile('content/events/old.md', 'Old event');
    mailed(removal('content/events/old.md', from: 'Old event'));

    $outcome = land();

    expect(contents('content/events/old.md'))->toBeNull()
        ->and($outcome->paths()->all())->toBe(['content/events/old.md']);
});

it('ignores a delete of a file that is already gone', function () {
    mailed(removal('content/events/old.md', from: 'Old event'));

    expect(land()->paths())->toBeEmpty();
});

it('writes nothing for a file added and removed before landing', function () {
    mailed(edit('content/draft.md', 'Draft'));
    mailed(removal('content/draft.md', from: 'Draft'));

    expect(land()->paths())->toBeEmpty()
        ->and(contents('content/draft.md'))->toBeNull();
});

it('leaves a diverged edit alone during catch-up', function () {
    $this->writeFile('content/home.md', 'Edited on this server');
    mailed(edit('content/home.md', 'Edited elsewhere', from: 'Welcome'));

    $outcome = land(Divergence::Skip);

    expect(contents('content/home.md'))->toBe('Edited on this server')
        ->and($outcome->skipped->all())->toBe(['content/home.md' => 'it changed here after the edit was made']);
});

it('takes the editor version of a diverged edit when pulling', function () {
    $this->writeFile('content/home.md', 'The version in git');
    mailed(edit('content/home.md', 'The editor version', from: 'Welcome'));

    land(Divergence::PreferEditor);

    expect(contents('content/home.md'))->toBe('The editor version');
});

it('merges a diverged edit that touches other lines', function () {
    $original = "title: Welcome\nsummary: Our church\nbody: Sunday at 9\n";

    $this->writeFile('content/home.md', "title: Welcome home\nsummary: Our church\nbody: Sunday at 9\n");
    mailed(edit('content/home.md', "title: Welcome\nsummary: Our church\nbody: Sunday at 10\n", from: $original));

    $outcome = land();

    expect(contents('content/home.md'))->toBe("title: Welcome home\nsummary: Our church\nbody: Sunday at 10\n")
        ->and($outcome->merged->keys()->all())->toBe(['content/home.md'])
        ->and($outcome->conflicts)->toBeEmpty();
});

it('turns an overlapping edit into a conflict and keeps the branch version', function () use ($andy) {
    $this->writeFile('content/home.md', "body: Sunday at 11\n");
    $batch = mailed(edit('content/home.md', "body: Sunday at 10\n", from: "body: Sunday at 9\n"), $andy);

    $outcome = land();
    $conflict = $outcome->conflicts->get('content/home.md');

    expect(contents('content/home.md'))->toBe("body: Sunday at 11\n")
        ->and($outcome->paths())->toBeEmpty()
        ->and($conflict->change->contents())->toBe("body: Sunday at 10\n")
        ->and($conflict->editor)->toEqual($andy)
        ->and($conflict->batchIds->all())->toBe([$batch->id]);
});

it('turns what cannot be merged into a conflict', function (Closure $scenario) {
    $scenario();

    $outcome = land();

    expect($outcome->conflicts->keys()->all())->toBe(['content/file'])
        ->and($outcome->paths())->toBeEmpty();
})->with([
    'the same file added on both sides' => function () {
        test()->writeFile('content/file', 'Added in git');
        mailed(edit('content/file', 'Added in the control panel'));
    },
    'binary content' => function () {
        test()->writeFile('content/file', "\x89PNG\0branch");
        mailed(edit('content/file', "\x89PNG\0editor", from: "\x89PNG\0original"));
    },
    'a base that is nowhere to be found' => function () {
        test()->writeFile('content/file', "one\ntwo\n");
        mailed(Change::put('content/file', test()->storeBlob("one\n2\n"), GitHash::of('lost'), 6));
    },
    'an edit to a file deleted in git' => function () {
        mailed(edit('content/file', "one\n2\n", from: "one\ntwo\n"));
    },
    'a delete of a file changed in git' => function () {
        test()->writeFile('content/file', 'Changed in git');
        mailed(removal('content/file', from: 'Original'));
    },
]);

it('handles a diverged delete by divergence', function (Divergence $divergence, ?string $expected) {
    $this->writeFile('content/file.md', 'Changed here');
    mailed(removal('content/file.md', from: 'Original'));

    land($divergence);

    expect(contents('content/file.md'))->toBe($expected);
})->with([
    'catch-up leaves it' => [Divergence::Skip, 'Changed here'],
    'pull takes the delete' => [Divergence::PreferEditor, null],
    'landing keeps it for the conflict' => [Divergence::Merge, 'Changed here'],
]);

it('sends every later change of a conflicted path to the conflict', function () use ($andy, $greg) {
    $this->writeFile('content/home.md', "body: branch\n");
    $first = mailed(edit('content/home.md', "body: andy\n", from: "body: original\n"), $andy);
    $second = mailed(edit('content/home.md', "body: greg\n", from: "body: branch\n"), $greg);
    $third = mailed(edit('content/home.md', "body: andy again\n", from: "body: greg\n"), $andy);

    $conflict = land()->conflicts->get('content/home.md');

    expect(contents('content/home.md'))->toBe("body: branch\n")
        ->and($conflict->change->contents())->toBe("body: andy again\n")
        ->and($conflict->batchIds->all())->toBe([$first->id, $second->id, $third->id])
        ->and($conflict->editors->all())->toEqual([$andy, $greg])
        ->and($conflict->editor)->toEqual($andy);
});

it('credits the conflict to the editor of its final version', function () use ($andy) {
    $this->writeFile('content/home.md', "body: branch\n");
    mailed(edit('content/home.md', "body: andy\n", from: "body: original\n"), $andy);
    mailed(edit('content/home.md', "body: scheduler\n", from: "body: andy\n"));

    expect(land()->conflicts->get('content/home.md')->editor)->toBeNull();
});

it('skips unsafe and untracked paths without writing them', function (string $path) {
    mailed(edit($path, 'nope'));

    $outcome = land(Divergence::PreferEditor);

    expect($outcome->skipped->keys()->all())->toBe([$path])
        ->and($outcome->paths())->toBeEmpty()
        ->and(File::exists(base_path($path)))->toBeFalse();
})->with([
    'a parent segment' => 'content/../escape.md',
    'git internals' => '.git/hooks/post-checkout',
    'an untracked path' => 'app/Models/User.php',
]);

it('merges against the version in git history', function () {
    $original = "title: Welcome\nsummary: Our church\nbody: Sunday at 9\n";

    $this->git('init', '--quiet');
    $this->writeFile('content/home.md', $original);
    $this->git('add', '--', 'content/home.md');
    $this->git('commit', '--quiet', '-m', 'base');
    $this->writeFile('content/home.md', "title: Welcome home\nsummary: Our church\nbody: Sunday at 9\n");
    $this->git('commit', '--quiet', '-am', 'branch');

    $editor = "title: Welcome\nsummary: Our church\nbody: Sunday at 10\n";
    mailed(Change::put('content/home.md', $this->storeBlob($editor), GitHash::of($original), strlen($editor)));

    Batch::pending()->applyTo(new GitTree(base_path()), Divergence::Merge)->writeTo(new GitTree(base_path()));

    expect(contents('content/home.md'))->toBe("title: Welcome home\nsummary: Our church\nbody: Sunday at 10\n");
});

it('knows when the mailbox has gone quiet', function () {
    expect(Batches::make()->isQuiet(120))->toBeTrue();

    mailed(edit('content/home.md', 'Welcome'));

    Carbon::setTestNow(now()->addSeconds(119));
    expect(Batch::pending()->isQuiet(120))->toBeFalse();

    Carbon::setTestNow(now()->addSecond());
    expect(Batch::pending()->isQuiet(120))->toBeTrue();
});

it('lists the editors, the newest batch and every blob still referenced', function () use ($andy, $greg) {
    mailed(edit('content/a.md', 'a2', from: 'a1'), $andy);
    mailed(edit('content/b.md', 'b1'), $greg);
    mailed(edit('content/c.md', 'c1'), $andy);
    $newest = mailed(removal('content/d.md', from: 'd1'));

    $batches = Batch::pending();

    expect($batches->editors()->all())->toEqual([$andy, $greg])
        ->and($batches->newest()->id)->toBe($newest->id)
        ->and($batches->blobs()->sort()->values()->all())->toBe(
            collect(['a1', 'a2', 'b1', 'c1', 'd1'])->map(fn (string $bytes) => GitHash::of($bytes))->sort()->values()->all()
        );
});
