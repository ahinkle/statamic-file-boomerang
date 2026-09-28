<?php

use Ahinkle\FileBoomerang\Batch;
use Ahinkle\FileBoomerang\Jobs\MailChanges;
use Ahinkle\FileBoomerang\Jobs\RequestLanding;
use Ahinkle\FileBoomerang\Manifest;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->writeFile('content/pages/home.md', 'Welcome');

    Manifest::seed()->save();
});

it('asks for a landing once the debounce has passed', function () {
    config(['queue.default' => 'database', 'file-boomerang.debounce' => 300]);
    Queue::fake();
    $this->freezeSecond();
    $this->writeFile('content/pages/home.md', 'Welcome home');

    MailChanges::dispatchSync();

    Queue::assertPushed(RequestLanding::class, fn (RequestLanding $job) => $job->delay->equalTo(now()->addSeconds(300)));
});

it('leaves the landing request to the scheduler on a sync queue', function () {
    Queue::fake();
    $this->writeFile('content/pages/home.md', 'Welcome home');

    MailChanges::dispatchSync();

    expect(Batch::pending())->toHaveCount(1);
    Queue::assertNothingPushed();
});

it('waits for another push on this server before looking for changes', function () {
    $lock = storage_path('framework/file-boomerang.lock');
    $home = base_path('content/pages/home.md');

    $holder = Process::start([PHP_BINARY, '-r', <<<PHP
        \$handle = fopen('{$lock}', 'c');
        flock(\$handle, LOCK_EX);
        echo 'locked';
        usleep(300000);
        file_put_contents('{$home}', 'Saved while locked');
        flock(\$handle, LOCK_UN);
        PHP]);

    $holder->waitUntil(fn (string $type, string $output) => str_contains($output, 'locked'));

    $batch = MailChanges::dispatchSync();

    expect($batch->changes->sole()->contents())->toBe('Saved while locked');

    $holder->wait();
});
