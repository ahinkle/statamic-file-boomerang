<?php

namespace Ahinkle\FileBoomerang\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Statamic\Console\RunsInPlease;

class Install extends Command
{
    use RunsInPlease;

    protected $signature = 'boomerang:install {--force : Overwrite the workflow if it already exists}';

    protected $description = 'Publish the config, write the GitHub workflow and list what is left to set up';

    public function handle(): int
    {
        $this->publishConfig();
        $this->writeWorkflow();
        $this->printChecklist();

        return self::SUCCESS;
    }

    protected function publishConfig(): void
    {
        if (File::exists(config_path('file-boomerang.php'))) {
            return;
        }

        $this->callSilently('vendor:publish', ['--tag' => 'file-boomerang-config']);

        $this->components->info('Published config/file-boomerang.php.');
    }

    protected function writeWorkflow(): void
    {
        if (File::exists($this->workflowPath()) && ! $this->option('force') && ! $this->components->confirm('The File Boomerang workflow already exists. Overwrite it?')) {
            return;
        }

        File::ensureDirectoryExists(dirname($this->workflowPath()));

        File::put($this->workflowPath(), str_replace(
            ['{{ branch }}', '{{ php }}'],
            [config('file-boomerang.github.branch'), PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION],
            File::get(dirname(__DIR__, 2).'/stubs/workflow.yml'),
        ));

        $this->components->info('Wrote .github/workflows/file-boomerang.yml.');
    }

    protected function printChecklist(): void
    {
        $this->components->bulletList([
            'On the host, set FILE_BOOMERANG_ENABLED=true, FILE_BOOMERANG_GITHUB_REPOSITORY (owner/repo) and FILE_BOOMERANG_GITHUB_TOKEN (a fine-grained token with Contents read and write on that repository).',
            'On the host, point the mailbox at a bucket with FILE_BOOMERANG_BUCKET, FILE_BOOMERANG_ENDPOINT, FILE_BOOMERANG_ACCESS_KEY_ID and FILE_BOOMERANG_SECRET_ACCESS_KEY, or name a disk from config/filesystems.php with FILE_BOOMERANG_DISK.',
            'On GitHub, add the repository secrets FILE_BOOMERANG_BUCKET, FILE_BOOMERANG_ENDPOINT, FILE_BOOMERANG_ACCESS_KEY_ID and FILE_BOOMERANG_SECRET_ACCESS_KEY. Add FILE_BOOMERANG_DEPLOY_HOOK too if your host does not deploy pushes made by GitHub Actions.',
            'Commit .github/workflows/file-boomerang.yml to the default branch. GitHub only runs repository_dispatch workflows from there.',
            'Build command, after composer install: php artisan boomerang:pull',
            'Deploy command, when the Stache cache is shared (Redis or the database): php please stache:refresh',
            'Check everything with: php artisan boomerang:doctor',
        ]);
    }

    protected function workflowPath(): string
    {
        return base_path('.github/workflows/file-boomerang.yml');
    }
}
