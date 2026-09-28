<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

function workflow(): string
{
    return base_path('.github/workflows/file-boomerang.yml');
}

it('writes a workflow that lands the mailbox on the branch with the four secrets', function () {
    config(['file-boomerang.github.branch' => 'production']);

    $this->artisan('boomerang:install')->assertSuccessful();

    $workflow = Yaml::parseFile(workflow());
    $job = $workflow['jobs']['land'];
    $secrets = collect($job['env'])
        ->filter(fn (string $value) => str_starts_with($value, '${{ secrets.'))
        ->keys();

    expect($workflow['on']['repository_dispatch']['types'])->toBe([config('file-boomerang.github.event')])
        ->and($secrets)->toContain('FILE_BOOMERANG_BUCKET', 'FILE_BOOMERANG_ENDPOINT', 'FILE_BOOMERANG_ACCESS_KEY_ID', 'FILE_BOOMERANG_SECRET_ACCESS_KEY')
        ->and($job['env']['FILE_BOOMERANG_GITHUB_BRANCH'])->toBe('production')
        ->and($job['steps'][0]['with']['ref'])->toBe('production')
        ->and($job['steps'][1]['with']['php-version'])->toBe(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION)
        ->and(last($job['steps'])['run'])->toBe('php artisan boomerang:land');
});

it('publishes the config once', function () {
    $this->artisan('boomerang:install')->assertSuccessful();

    expect(File::get(config_path('file-boomerang.php')))->toBe(File::get(dirname(__DIR__).'/config/file-boomerang.php'));

    File::put(config_path('file-boomerang.php'), '<?php return [];');

    $this->artisan('boomerang:install', ['--force' => true])->assertSuccessful();

    expect(File::get(config_path('file-boomerang.php')))->toBe('<?php return [];');
});

it('asks before overwriting a workflow that is already there', function () {
    File::ensureDirectoryExists(dirname(workflow()));
    File::put(workflow(), 'name: Customized');

    $this->artisan('boomerang:install')
        ->expectsConfirmation('The File Boomerang workflow already exists. Overwrite it?', 'no')
        ->assertSuccessful();

    expect(File::get(workflow()))->toBe('name: Customized');

    $this->artisan('boomerang:install', ['--force' => true])->assertSuccessful();

    expect(Yaml::parseFile(workflow())['name'])->toBe('File Boomerang');
});

it('lists what is left to set up', function () {
    $this->artisan('boomerang:install')
        ->expectsOutputToContain('FILE_BOOMERANG_SECRET_ACCESS_KEY')
        ->expectsOutputToContain('php artisan boomerang:pull')
        ->expectsOutputToContain('php artisan boomerang:doctor')
        ->assertSuccessful();
});
