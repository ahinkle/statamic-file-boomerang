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
    $land = last($job['steps']);
    $secrets = collect($land['env'])
        ->filter(fn (string $value) => str_starts_with($value, '${{ secrets.'))
        ->keys();

    expect($workflow['on'])->toHaveKey('workflow_dispatch')
        ->and($land['run'])->toBe('php artisan boomerang:land')
        ->and($secrets)->toContain('FILE_BOOMERANG_BUCKET', 'FILE_BOOMERANG_ENDPOINT', 'FILE_BOOMERANG_ACCESS_KEY_ID', 'FILE_BOOMERANG_SECRET_ACCESS_KEY')
        ->and(collect($job['steps'])->except(array_key_last($job['steps']))->pluck('env')->filter())->toBeEmpty()
        ->and($land['env']['FILE_BOOMERANG_GITHUB_BRANCH'])->toBe('production')
        ->and($job['env']['CACHE_STORE'])->toBe('array')
        ->and($job['steps'][0]['with']['ref'])->toBe('production')
        ->and($job['steps'][1]['with']['php-version'])->toBe(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION);
});

it('never overwrites a published config', function () {
    File::ensureDirectoryExists(config_path());
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
        ->expectsOutputToContain('/storage/framework/file-boomerang*')
        ->expectsOutputToContain('to land edits on main')
        ->expectsOutputToContain('php artisan boomerang:doctor')
        ->assertSuccessful();
});
