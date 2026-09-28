<?php

namespace Ahinkle\FileBoomerang\Commands;

use Ahinkle\FileBoomerang\Jobs\RequestLanding;
use Ahinkle\FileBoomerang\Mailbox;
use Ahinkle\FileBoomerang\Manifest;
use Ahinkle\FileBoomerang\Paths;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Statamic\Console\RunsInPlease;
use Throwable;

class DoctorCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'boomerang:doctor';

    protected $description = 'Check that File Boomerang can carry edits from this server to git';

    protected int $checked = 0;

    public function handle(): int
    {
        $failures = $this->check($this->requirements(), '<fg=red;options=bold>FAIL</>');

        $this->check($this->recommendations(), '<fg=yellow;options=bold>WARN</>');

        return $failures->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, Closure(): ?string>
     */
    protected function requirements(): array
    {
        return [
            'File Boomerang is enabled' => fn () => config('file-boomerang.enabled') ? null : 'Set FILE_BOOMERANG_ENABLED=true on the host.',
            'The mailbox can be written, read and cleaned up' => fn () => $this->mailboxProblem(),
            'This server has a baseline' => fn () => Manifest::current() ? null : 'Add "php artisan boomerang:pull" to the end of your build command.',
            $this->trackedPathsName() => fn () => $this->existingTrackedPaths()->isNotEmpty() ? null : 'None of the tracked paths exist. Check the paths in config/file-boomerang.php.',
            'The GitHub repository and token are set' => fn () => $this->githubSettingsProblem(),
            'The token can see the repository and its workflow' => fn () => $this->githubAccessProblem(),
            'The cache is shared with the queue workers' => fn () => $this->cacheProblem(),
            'The debounce is under 15 minutes' => fn () => config('file-boomerang.debounce') < 900 ? null : 'Set FILE_BOOMERANG_DEBOUNCE below 900. Most queues cannot delay a job longer than 15 minutes.',
        ];
    }

    /**
     * @return array<string, Closure(): ?string>
     */
    protected function recommendations(): array
    {
        return [
            'A queue waits out the debounce' => fn () => RequestLanding::canWait() ? null : 'The queue connection is sync, so the scheduler asks for landings instead. Keep the scheduler running every minute.',
            'Sessions survive a deploy' => fn () => config('session.driver') === 'file' ? 'File sessions are wiped on every deploy, which signs editors out. Use database or redis for SESSION_DRIVER.' : null,
        ];
    }

    /**
     * @param  array<string, Closure(): ?string>  $checks
     * @return Collection<string, string>
     */
    protected function check(array $checks, string $label): Collection
    {
        return collect($checks)
            ->map(fn (Closure $check) => rescue($check, fn (Throwable $e) => $e->getMessage(), report: false))
            ->each(fn (?string $fix, string $name) => $this->report($name, $fix, $label))
            ->filter();
    }

    protected function report(string $name, ?string $fix, string $label): void
    {
        $this->components->twoColumnDetail(++$this->checked.'. '.$name, $fix ? $label : '<fg=green;options=bold>PASS</>');

        if ($fix) {
            $this->line("    <fg=gray>{$fix}</>");
        }
    }

    protected function mailboxProblem(): ?string
    {
        $probe = Mailbox::path('state', 'doctor-'.Str::random(12));

        return rescue(function () use ($probe) {
            Mailbox::disk()->put($probe, 'boomerang');

            $read = Mailbox::disk()->get($probe);

            Mailbox::disk()->delete($probe);

            return $read === 'boomerang' ? null : 'The mailbox returned different bytes than it was given.';
        }, fn (Throwable $e) => "Check the FILE_BOOMERANG_* mailbox settings. {$e->getMessage()}", report: false);
    }

    protected function trackedPathsName(): string
    {
        return 'Tracks '.($this->existingTrackedPaths()->implode(', ') ?: 'nothing yet');
    }

    /**
     * @return Collection<int, string>
     */
    protected function existingTrackedPaths(): Collection
    {
        return Paths::tracked()->filter(fn (string $path) => file_exists(base_path($path)))->values();
    }

    protected function githubSettingsProblem(): ?string
    {
        return match (true) {
            blank(config('file-boomerang.github.repository')) => 'Set FILE_BOOMERANG_GITHUB_REPOSITORY to owner/repository.',
            blank(config('file-boomerang.github.token')) => 'Set FILE_BOOMERANG_GITHUB_TOKEN to a fine-grained token with Contents: Read and write.',
            default => null,
        };
    }

    protected function githubAccessProblem(): ?string
    {
        $repository = config('file-boomerang.github.repository');
        $branch = config('file-boomerang.github.branch');

        return match (true) {
            $this->githubSettingsProblem() !== null => 'Set the repository and token first.',
            Http::github()->get("repos/{$repository}")->failed() => "The token cannot see {$repository}. Give it access to that repository.",
            Http::github()->get("repos/{$repository}/contents/.github/workflows/file-boomerang.yml", ['ref' => $branch])->failed() => "There is no .github/workflows/file-boomerang.yml on {$branch}. Run \"php artisan boomerang:install\" and push it.",
            default => null,
        };
    }

    protected function cacheProblem(): ?string
    {
        $driver = config('cache.stores.'.config('cache.default').'.driver');

        if (! RequestLanding::canWait() || ! in_array($driver, ['file', 'array'])) {
            return null;
        }

        return "The {$driver} cache is not shared with the queue workers, so landing requests can be dropped. Use database or redis for CACHE_STORE.";
    }
}
