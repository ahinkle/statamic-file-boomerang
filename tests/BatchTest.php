<?php

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Change;
use Ahinkle\FileBoomerang\Editor;
use Ahinkle\FileBoomerang\Exceptions\InvalidBatch;
use Illuminate\Support\Carbon;
use Statamic\Facades\User;

it('mails a batch in the documented format', function () {
    Carbon::setTestNow('2026-09-27 20:15:03');

    $blob = str_repeat('a', 40);
    $base = str_repeat('b', 40);

    $batch = Batch::record(collect([
        Change::put('content/collections/pages/home.md', $blob, null, 1234),
        Change::delete('public/img/old.jpg', $base),
    ]), new Editor('Andy Hinkle', 'andy@example.com'));

    expect($this->mailbox()->json("file-boomerang/batches/{$batch->id}.json"))->toBe([
        'version' => 1,
        'id' => $batch->id,
        'created_at' => '2026-09-27T20:15:03+00:00',
        'host' => gethostname(),
        'editor' => ['name' => 'Andy Hinkle', 'email' => 'andy@example.com'],
        'ops' => [
            ['path' => 'content/collections/pages/home.md', 'type' => 'put', 'blob' => $blob, 'base' => null, 'size' => 1234],
            ['path' => 'public/img/old.jpg', 'type' => 'delete', 'base' => $base],
        ],
    ]);
});

it('reads back what it mailed', function () {
    $batch = Batch::record(collect([Change::put('content/home.md', str_repeat('c', 40), str_repeat('d', 40), 7)]), null);

    $found = Batch::find($batch->id);

    expect($found->toArray())->toBe($batch->toArray())
        ->and($found->editor)->toBeNull()
        ->and($found->createdAt()->toIso8601String())->toBe($batch->createdAt()->toIso8601String());
});

it('lists pending batches oldest first and after a cursor', function () {
    $batches = collect(range(1, 3))->map(function (int $minute) {
        Carbon::setTestNow(now()->addMinute());

        return Batch::record(collect([Change::put("content/{$minute}.md", str_repeat('e', 40), null, 1)]), null);
    });

    expect(Batch::pending()->pluck('id')->all())->toBe($batches->pluck('id')->all())
        ->and(Batch::after($batches->first()->id)->pluck('id')->all())->toBe($batches->skip(1)->pluck('id')->values()->all())
        ->and(Batch::after($batches->last()->id))->toBeEmpty()
        ->and(Batch::after(null))->toHaveCount(3);
});

it('ignores keys in the batches folder that are not batches', function () {
    $this->mailbox()->put('file-boomerang/batches/notes.txt', 'hello');

    expect(Batch::pending())->toBeEmpty();
});

it('forgets a batch once it is deleted', function () {
    $batch = Batch::record(collect([Change::put('content/a.md', str_repeat('f', 40), null, 1)]), null);

    $batch->delete();

    expect(Batch::pending())->toBeEmpty();
});

it('refuses batches it cannot trust', function (array $overrides) {
    Batch::fromArray(array_replace([
        'version' => 1,
        'id' => '01J8ZQ6X9R6B7Y5M3N2P1K0H9G',
        'created_at' => '2026-09-27T20:15:03+00:00',
        'host' => 'web-7f9c',
        'editor' => null,
        'ops' => [['path' => 'content/a.md', 'type' => 'put', 'blob' => str_repeat('a', 40), 'base' => null, 'size' => 1]],
    ], $overrides));
})->throws(InvalidBatch::class)->with([
    'an unknown version' => [['version' => 2]],
    'an id that is not a ULID' => [['id' => '../../etc/passwd']],
    'no created_at' => [['created_at' => null]],
    'a created_at that is not a date' => [['created_at' => 'yesterday-ish?']],
    'no host' => [['host' => null]],
    'an editor that is not an object' => [['editor' => 'andy']],
    'an editor without an email' => [['editor' => ['name' => 'Andy']]],
    'ops that are not a list' => [['ops' => ['a' => []]]],
    'an op that is not an object' => [['ops' => ['content/a.md']]],
    'an op without a path' => [['ops' => [['type' => 'delete', 'base' => str_repeat('a', 40)]]]],
    'an unknown op type' => [['ops' => [['path' => 'content/a.md', 'type' => 'rename']]]],
    'a blob that is not a hash' => [['ops' => [['path' => 'content/a.md', 'type' => 'put', 'blob' => 'abc', 'base' => null, 'size' => 1]]]],
    'an uppercase hash' => [['ops' => [['path' => 'content/a.md', 'type' => 'put', 'blob' => str_repeat('A', 40), 'base' => null, 'size' => 1]]]],
    'a put without a base' => [['ops' => [['path' => 'content/a.md', 'type' => 'put', 'blob' => str_repeat('a', 40), 'size' => 1]]]],
    'a put without a size' => [['ops' => [['path' => 'content/a.md', 'type' => 'put', 'blob' => str_repeat('a', 40), 'base' => null]]]],
    'a delete without a base' => [['ops' => [['path' => 'content/a.md', 'type' => 'delete', 'base' => null]]]],
]);

it('refuses a batch that is not json', function () {
    $this->mailbox()->put('file-boomerang/batches/01J8ZQ6X9R6B7Y5M3N2P1K0H9G.json', '{not json');

    Batch::pending();
})->throws(InvalidBatch::class);

it('names the editor from the control panel user', function () {
    $user = User::make()->email('greg@example.com')->set('name', "Greg <Davis>\n");

    expect(Editor::from($user))->toEqual(new Editor('Greg Davis', 'greg@example.com'));
});

it('falls back to the email when the user has no name', function () {
    expect(Editor::from(User::make()->email('office@example.com')))
        ->toEqual(new Editor('office@example.com', 'office@example.com'));
});

it('has no editor for system writes', function () {
    expect(Editor::from(null))->toBeNull();
});
