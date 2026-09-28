<?php

namespace Ahinkle\FileBoomerang\Tests;

use Ahinkle\FileBoomerang\Blob;
use Ahinkle\FileBoomerang\Mailbox;
use Ahinkle\FileBoomerang\ServiceProvider;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\Filesystem as Files;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Statamic\Testing\AddonTestCase;

use function Orchestra\Testbench\default_skeleton_path;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    protected static string $sandbox;

    protected function setUp(): void
    {
        static::$sandbox = realpath(sys_get_temp_dir()).'/file-boomerang-tests/'.Str::random(16);

        (new Files)->copyDirectory(default_skeleton_path(), static::$sandbox.'/app');

        parent::setUp();

        $this->useLocalMailbox();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Files)->deleteDirectory(static::$sandbox);
    }

    public static function applicationBasePath(): string
    {
        return static::$sandbox.'/app';
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        collect([
            'taxonomies' => 'content/taxonomies',
            'terms' => 'content/taxonomies',
            'collections' => 'content/collections',
            'entries' => 'content/collections',
            'navigation' => 'content/navigation',
            'collection-trees' => 'content/trees/collections',
            'nav-trees' => 'content/trees/navigation',
            'globals' => 'content/globals',
            'global-variables' => 'content/globals',
            'asset-containers' => 'content/assets',
            'users' => 'users',
            'form-submissions' => 'storage/forms',
        ])->each(fn (string $directory, string $store) => $app['config']->set(
            "statamic.stache.stores.{$store}.directory", base_path($directory)
        ));
    }

    protected function useLocalMailbox(): void
    {
        config([
            'filesystems.disks.file-boomerang-mailbox' => [
                'driver' => 'local',
                'root' => static::$sandbox.'/mailbox',
            ],
            'file-boomerang.mailbox.disk' => 'file-boomerang-mailbox',
        ]);
    }

    public function mailbox(): Filesystem
    {
        return Mailbox::disk();
    }

    public function writeFile(string $path, string $contents, ?int $modifiedAt = null): string
    {
        (new Files)->ensureDirectoryExists(dirname(base_path($path)));

        file_put_contents(base_path($path), $contents);

        touch(base_path($path), $modifiedAt ?? time() - 60);

        return base_path($path);
    }

    public function storeBlob(string $contents): string
    {
        $path = static::$sandbox.'/'.Str::random(16);

        file_put_contents($path, $contents);

        return tap(Blob::store($path), fn () => unlink($path));
    }

    public function git(string ...$arguments): string
    {
        return Process::path(base_path())
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
}
