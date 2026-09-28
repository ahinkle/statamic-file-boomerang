<?php

namespace Ahinkle\FileBoomerang;

use Ahinkle\FileBoomerang\Exceptions\LandingRejected;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

class Landing
{
    protected string $branch = 'main';

    public static function make(): static
    {
        return new static;
    }

    public function onto(string $branch): static
    {
        $this->branch = $branch;

        return $this;
    }

    public function land(): LandingResult
    {
        $this->ensureReady();

        return tap($this->landBatches(Batch::pending()), fn (LandingResult $result) => $this->announce($result));
    }

    public function dryRun(): LandingResult
    {
        return LandingResult::from(Batch::pending()->applyTo(new GitTree(base_path()), Divergence::Merge));
    }

    protected function ensureReady(): void
    {
        throw_unless($this->isClean(), LandingRejected::class, 'The checkout has uncommitted changes. Landing needs a clean working tree.');

        throw_unless($this->currentBranch() === $this->branch, LandingRejected::class, "The checkout is not on the [{$this->branch}] branch.");

        $this->fetch();

        throw_unless(
            $this->git('merge-base', '--is-ancestor', 'HEAD', "origin/{$this->branch}")->successful(),
            LandingRejected::class,
            "The checkout has commits that are not on origin/{$this->branch}. Push or drop them before landing.",
        );
    }

    protected function landBatches(Batches $batches): LandingResult
    {
        if ($batches->isEmpty()) {
            return new LandingResult(sha: $this->head());
        }

        $outcome = retry(4, fn () => $this->landOnce($batches), when: fn (Throwable $e) => $e instanceof LandingRejected);

        return tap(
            LandingResult::from($outcome, $this->head(), $this->openConflicts($outcome, $batches)),
            fn () => $this->cleanUp($batches),
        );
    }

    protected function landOnce(Batches $batches): Outcome
    {
        $this->fetch();

        $this->git('reset', '--hard', '--quiet', "origin/{$this->branch}")->throw();

        $outcome = $batches->applyTo(new GitTree(base_path()), Divergence::Merge);

        $outcome->writeTo($outcome->tree);

        $this->stage($outcome->paths());

        if ($this->hasStagedChanges()) {
            $this->commit(config('file-boomerang.landing.commit_message'), $outcome->paths(), $this->authorOf($batches, $outcome), $batches->editors());

            $this->push();
        }

        return $outcome;
    }

    protected function openConflicts(Outcome $outcome, Batches $batches): ?string
    {
        if ($outcome->conflicts->isEmpty()) {
            return null;
        }

        $branch = "file-boomerang/conflict-{$batches->first()->id}";

        $this->pushConflictBranch($outcome->conflicts, $branch);

        return $this->openPullRequest($outcome->conflicts, $branch)
            ?? $this->openIssue($outcome->conflicts, $branch)
            ?? throw new LandingRejected("GitHub refused both a pull request and an issue for the conflicts on [{$branch}], so every batch stays in the mailbox.");
    }

    /**
     * @param  Collection<string, Conflict>  $conflicts
     */
    protected function pushConflictBranch(Collection $conflicts, string $branch): void
    {
        $this->git('switch', '--quiet', '--force-create', $branch)->throw();

        try {
            $conflicts->pluck('change')->each->writeTo(new GitTree(base_path()));

            $this->stage($conflicts->keys());

            if ($this->hasStagedChanges()) {
                $this->commit($this->conflictTitle(), $conflicts->keys(), $conflicts->last()->editor ?? $this->fallbackAuthor(), $conflicts->flatMap->editors);
            }

            $this->git('push', '--quiet', '--force', 'origin', "HEAD:refs/heads/{$branch}")->throw();
        } finally {
            $this->git('switch', '--quiet', '--discard-changes', $this->branch);
        }
    }

    /**
     * @param  Collection<string, Conflict>  $conflicts
     */
    protected function openPullRequest(Collection $conflicts, string $branch): ?string
    {
        $response = Http::github()->post("repos/{$this->repository()}/pulls", [
            'title' => $this->conflictTitle(),
            'head' => $branch,
            'base' => $this->branch,
            'body' => $this->conflictReport(
                "Some control panel edits could not be merged into `{$this->branch}` because the same lines changed in git first. This pull request holds the editors' versions. Resolve the differences and merge it, or close it to keep `{$this->branch}` as it is.",
                $conflicts,
            ),
        ]);

        return $response->successful() ? $response->json('html_url') : null;
    }

    /**
     * @param  Collection<string, Conflict>  $conflicts
     */
    protected function openIssue(Collection $conflicts, string $branch): ?string
    {
        $compare = "https://github.com/{$this->repository()}/compare/{$this->branch}...{$branch}?expand=1";

        $response = Http::github()->post("repos/{$this->repository()}/issues", [
            'title' => $this->conflictTitle(),
            'body' => $this->conflictReport(
                "Some control panel edits could not be merged into `{$this->branch}` because the same lines changed in git first. GitHub Actions is not allowed to open pull requests in this repository, so the editors' versions are on the branch [`{$branch}`]({$compare}). Open a pull request from it to review them. To let File Boomerang open it next time, turn on \"Allow GitHub Actions to create and approve pull requests\" under Settings, Actions, General.",
                $conflicts,
            ),
        ]);

        return $response->successful() ? $response->json('html_url') : null;
    }

    /**
     * @param  Collection<string, Conflict>  $conflicts
     */
    protected function conflictReport(string $introduction, Collection $conflicts): string
    {
        $rows = $conflicts->map(fn (Conflict $conflict) => $this->tableRow(
            '`'.$conflict->path().'`'.($conflict->change->isDelete() ? ' (deleted)' : ''),
            $conflict->editors->pluck('name')->implode(', ') ?: 'System',
            $conflict->batchIds->implode(', '),
        ));

        return "{$introduction}\n\n| File | Editors | Batches |\n| --- | --- | --- |\n".$rows->implode("\n");
    }

    protected function tableRow(string ...$cells): string
    {
        return '| '.collect($cells)->map(fn (string $cell) => str_replace('|', '\|', $cell))->implode(' | ').' |';
    }

    protected function conflictTitle(): string
    {
        return "Control panel edits that conflict with {$this->branch}";
    }

    protected function cleanUp(Batches $batches): void
    {
        $batches->each->delete();

        Blob::prune(Batch::pending(), now()->subMinutes(config('file-boomerang.landing.blob_grace')));
    }

    protected function announce(LandingResult $result): void
    {
        $this->writeOutput($result);
        $this->writeStepSummary($result);

        if (! $result->landed()) {
            return;
        }

        $this->callDeployHook($result->sha);
        $this->dispatchWorkflows();
    }

    protected function writeOutput(LandingResult $result): void
    {
        if (blank($output = Env::get('GITHUB_OUTPUT'))) {
            return;
        }

        File::append($output, collect([
            'landed' => $result->landed() ? 'true' : 'false',
            'sha' => $result->sha,
            'conflicts' => $result->conflicts->count(),
        ])->map(fn (mixed $value, string $key) => "{$key}={$value}\n")->implode(''));
    }

    protected function writeStepSummary(LandingResult $result): void
    {
        if (blank($summary = Env::get('GITHUB_STEP_SUMMARY'))) {
            return;
        }

        $rows = $result->paths->mapWithKeys(fn (string $path) => [$path => 'landed'])
            ->merge($result->conflicts->map(fn () => 'conflict'))
            ->merge($result->skipped->map(fn (string $reason) => "skipped because {$reason}"))
            ->map(fn (string $status, string $path) => $this->tableRow("`{$path}`", $status));

        File::append($summary, collect(['### File Boomerang', $result->summary()])
            ->when($rows->isNotEmpty(), fn (Collection $lines) => $lines->push("| File | Result |\n| --- | --- |\n".$rows->implode("\n")))
            ->implode("\n\n")."\n");
    }

    protected function callDeployHook(string $sha): void
    {
        if (blank($hook = config('file-boomerang.landing.deploy_hook'))) {
            return;
        }

        Http::post(Str::replace('{sha}', $sha, $hook))->throw();
    }

    protected function dispatchWorkflows(): void
    {
        collect(config('file-boomerang.landing.workflows'))->each(fn (string $workflow) => Http::github()
            ->post("repos/{$this->repository()}/actions/workflows/".rawurlencode($workflow).'/dispatches', ['ref' => $this->branch])
            ->throw());
    }

    /**
     * @param  Collection<int, string>  $paths
     */
    protected function stage(Collection $paths): void
    {
        [$present, $missing] = $paths->partition(fn (string $path) => File::exists(base_path($path)));

        $present->chunk(100)->each(fn (Collection $chunk) => $this->git('add', '--force', '--', ...$chunk)->throw());
        $missing->chunk(100)->each(fn (Collection $chunk) => $this->git('rm', '--cached', '--quiet', '--ignore-unmatch', '--', ...$chunk)->throw());
    }

    protected function hasStagedChanges(): bool
    {
        return $this->git('diff', '--cached', '--quiet')->failed();
    }

    /**
     * @param  Collection<int, string>  $paths
     * @param  Collection<int, Editor>  $editors
     */
    protected function commit(string $subject, Collection $paths, Editor $author, Collection $editors): void
    {
        $coAuthors = $editors->reject(fn (Editor $editor) => $editor->email === $author->email);

        $this->process()
            ->input(collect([$subject, $this->pathList($paths), $this->trailers($coAuthors)])->filter()->implode("\n\n"))
            ->run(['git', 'commit', '--quiet', "--author={$author->name} <{$author->email}>", '--file=-'])
            ->throw();
    }

    /**
     * @param  Collection<int, string>  $paths
     */
    protected function pathList(Collection $paths): string
    {
        return $paths->take(30)
            ->when($paths->count() > 30, fn (Collection $lines) => $lines->push('and '.($paths->count() - 30).' more'))
            ->implode("\n");
    }

    /**
     * @param  Collection<int, Editor>  $coAuthors
     */
    protected function trailers(Collection $coAuthors): string
    {
        return $coAuthors->map(fn (Editor $editor) => "Co-authored-by: {$editor->name} <{$editor->email}>")->implode("\n");
    }

    protected function push(): void
    {
        $result = $this->git('push', '--quiet', 'origin', "HEAD:refs/heads/{$this->branch}");

        throw_if(
            $result->failed(),
            LandingRejected::class,
            "Git could not push to [{$this->branch}], so every batch stays in the mailbox. ".trim($result->errorOutput()),
        );
    }

    protected function authorOf(Batches $batches, Outcome $outcome): Editor
    {
        return $batches
            ->filter(fn (Batch $batch) => $batch->changes->pluck('path')->intersect($outcome->paths())->isNotEmpty())
            ->newest()?->editor ?? $this->fallbackAuthor();
    }

    protected function fallbackAuthor(): Editor
    {
        return Editor::fromArray(config('file-boomerang.landing.author'));
    }

    protected function fetch(): void
    {
        $this->git('fetch', '--quiet', 'origin', "+refs/heads/{$this->branch}:refs/remotes/origin/{$this->branch}")->throw();
    }

    protected function isClean(): bool
    {
        return $this->git('status', '--porcelain')->throw()->output() === '';
    }

    protected function currentBranch(): string
    {
        return trim($this->git('symbolic-ref', '--quiet', '--short', 'HEAD')->output());
    }

    protected function head(): string
    {
        return trim($this->git('rev-parse', 'HEAD')->throw()->output());
    }

    protected function repository(): ?string
    {
        return config('file-boomerang.github.repository');
    }

    protected function git(string ...$arguments): ProcessResult
    {
        return $this->process()->run(['git', ...$arguments]);
    }

    protected function process(): PendingProcess
    {
        return Process::path(base_path())->timeout(600)->env([
            'GIT_LITERAL_PATHSPECS' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_COMMITTER_NAME' => Env::get('GIT_COMMITTER_NAME', 'github-actions[bot]'),
            'GIT_COMMITTER_EMAIL' => Env::get('GIT_COMMITTER_EMAIL', '41898282+github-actions[bot]@users.noreply.github.com'),
        ]);
    }
}
