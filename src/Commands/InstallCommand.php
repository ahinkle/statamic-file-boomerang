<?php

namespace Ahinkle\FileBoomerang\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Statamic\Console\RunsInPlease;

class InstallCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'boomerang:install {--force : Overwrite the workflow if it already exists}';

    protected $description = 'Publish the config, write the GitHub workflow and list what is left to set up';

    public function handle(): int
    {
        $this->publishConfig();

        if ($this->shouldWriteWorkflow()) {
            $this->writeWorkflow();
        }

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

    protected function shouldWriteWorkflow(): bool
    {
        if (! File::exists($this->workflowPath()) || $this->option('force')) {
            return true;
        }

        return $this->components->confirm('The File Boomerang workflow already exists. Overwrite it?');
    }

    protected function writeWorkflow(): void
    {
        File::ensureDirectoryExists(dirname($this->workflowPath()));

        File::put($this->workflowPath(), str_replace(
            ['{{ branch }}', '{{ php }}'],
            [$this->branch(), PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION],
            File::get(dirname(__DIR__, 2).'/stubs/workflow.yml'),
        ));

        $this->components->info("Wrote .github/workflows/file-boomerang.yml to land edits on {$this->branch()}. Set FILE_BOOMERANG_GITHUB_BRANCH and run this again with --force to use another branch.");
    }

    protected function printChecklist(): void
    {
        $this->components->info('Finish the setup:');

        collect([
            'On the host, set FILE_BOOMERANG_ENABLED=true, FILE_BOOMERANG_GITHUB_REPOSITORY (owner/repo) and FILE_BOOMERANG_GITHUB_TOKEN (a fine-grained token with Actions read and write on that repository).',
            'On the host, point the mailbox at a bucket with FILE_BOOMERANG_BUCKET, FILE_BOOMERANG_ENDPOINT, FILE_BOOMERANG_ACCESS_KEY_ID and FILE_BOOMERANG_SECRET_ACCESS_KEY. The GitHub Action always reads the mailbox from those four secrets, so a disk named with FILE_BOOMERANG_DISK must use the same bucket with no root or prefix.',
            'On GitHub, add the repository secrets FILE_BOOMERANG_BUCKET, FILE_BOOMERANG_ENDPOINT, FILE_BOOMERANG_ACCESS_KEY_ID and FILE_BOOMERANG_SECRET_ACCESS_KEY. Add FILE_BOOMERANG_DEPLOY_HOOK too if your host does not deploy pushes made by GitHub Actions.',
            'Commit .github/workflows/file-boomerang.yml to the default branch. GitHub only starts workflows that are there.',
            'Add /storage/framework/file-boomerang* to .gitignore so no server baseline is ever committed.',
            'Build command, right after composer install and before optimize or any cache warming: php artisan boomerang:pull',
            'Deploy command, when the Stache cache is shared (Redis or the database): php please stache:refresh',
            'Check everything with: php artisan boomerang:doctor',
        ])->each(fn (string $step, int $index) => $this->line(($index + 1).". {$step}"));
    }

    protected function branch(): string
    {
        return config()->string('file-boomerang.github.branch');
    }

    protected function workflowPath(): string
    {
        return base_path('.github/workflows/file-boomerang.yml');
    }
}
