# File Boomerang

Git automation for Statamic on hosts without a persistent disk.

## What it is

File Boomerang saves control panel edits back to your Git repository when your site runs somewhere the disk does not last, like Laravel Cloud, Vapor, containers or autoscaled servers.

Statamic's Git automation (and addons like it) run `git` inside the app's working tree. That needs a server with a permanent checkout and push access. Statamic's own Laravel Cloud guide says its Git automation does not work there: every deploy starts from a fresh copy of the repository, so commits made on the server are thrown away.

File Boomerang takes a different route. The server never runs Git:

1. When an editor saves in the control panel, the changed files are mailed to a private object storage bucket.
2. A GitHub Action picks them up and commits them to your branch, authored by the editor.
3. Your host deploys that commit like any other push. Git stays the only source of truth.

The bucket is a mailbox, not a second home for your content. It empties itself once the edits are in Git.

It works with Statamic Core and Pro, and with flat file content, users, blueprints, fieldsets, forms, navigation and globals, plus uploads to asset containers stored on the local disk.

## How it works

```
 Editor saves in the control panel
             |
             v
 Server disk (the site shows the edit right away)
             |  after the response: changed files are hashed and uploaded
             v
 Mailbox bucket: blobs/<hash> and batches/<id>.json
             |  after a quiet period (debounce): a workflow_dispatch request
             v
 GitHub Action: php artisan boomerang:land
   applies every waiting batch to a fresh checkout
   merges with anything developers pushed in the meantime
   commits as the editor, pushes, empties the mailbox
             |
             v
 Your host deploys the commit
   the build runs php artisan boomerang:pull
```

In a little more detail:

- Each server keeps a small baseline file (`storage/framework/file-boomerang.json`) with the Git hash of every tracked file. After a save it compares the disk against the baseline and mails only what changed.
- Only files whose contents changed are uploaded, each under its Git hash.
- Each batch records the version the editor started from. The Action uses that to fast-forward clean edits and to run a three-way merge when a developer changed the same file. If the two cannot be merged, the editor's version goes to its own branch and pull request, so nothing is lost. One save lands whole or goes to that branch whole, so a renamed entry never ends up on the branch twice.
- Batches are only removed from the mailbox after the commit and any conflict branch are pushed. If a run fails before that, the edits wait and the scheduler asks again.
- The build step applies waiting edits to files Git has not changed since the edit was made. If a developer changed the same file, the build keeps Git's version and the landing merges the two and deploys again. Edits saved on the old server while the new one was building reach it within a minute, when its scheduler catches up.

## Install

```bash
composer require ahinkle/statamic-file-boomerang
php artisan boomerang:install
```

`boomerang:install` publishes `config/file-boomerang.php`, writes `.github/workflows/file-boomerang.yml` for the branch in `FILE_BOOMERANG_GITHUB_BRANCH` (`main` by default) and prints what is left to set up. Commit the workflow to your default branch. GitHub only starts workflows that are there.

Each server keeps its baseline and lock in `storage/framework`, so add this line to your `.gitignore`:

```
/storage/framework/file-boomerang*
```

Every command also works through `php please`.

## Laravel Cloud recipe

### 1. Create the mailbox bucket

1. On the environment's canvas, click **Add bucket** and create a new **Laravel Object Storage** bucket.
2. Set the visibility to **Private**. The mailbox holds drafts and user files.
3. Give it any disk name, and **do not tick the default disk option**. If you do, everything that uses the default disk (Livewire uploads, Filament uploads) starts writing into the mailbox.
4. Save and redeploy.
5. Open **Resources > Object storage**, click **...** next to the bucket, then **View credentials**. Copy the four values shown there.

### 2. Put the four values in two places

In Cloud, under the environment's **Settings > Environment variables**, and in GitHub, under the repository's **Settings > Secrets and variables > Actions > New repository secret**, add:

| Name | Value from View credentials |
| --- | --- |
| `FILE_BOOMERANG_BUCKET` | Bucket name |
| `FILE_BOOMERANG_ENDPOINT` | Endpoint, including `https://` |
| `FILE_BOOMERANG_ACCESS_KEY_ID` | Access key ID |
| `FILE_BOOMERANG_SECRET_ACCESS_KEY` | Access key secret |

The region defaults to `auto`, which is what Cloud's buckets expect.

### 3. Add the rest of the Cloud environment variables

```
FILE_BOOMERANG_ENABLED=true
FILE_BOOMERANG_GITHUB_REPOSITORY=owner/repository
FILE_BOOMERANG_GITHUB_TOKEN=github_pat_...
CACHE_STORE=database
SESSION_DRIVER=database
```

The token only lives in Cloud. See [GitHub setup](#github-setup) for how to make it.

Use `redis` instead of `database` if you attach a Valkey cache. The cache must be shared by every server and worker because it holds the lock that stops duplicate landing requests. Sessions must survive a deploy, or every landed edit signs your editors out. `database` sessions need the sessions table (`php artisan make:session-table`).

### 4. Build and deploy commands

Under **Settings > Deployments**:

- **Build commands**: keep what you have and add this right after `composer install`, before `php artisan optimize` or any cache warming:
  ```
  php artisan boomerang:pull
  ```
  It applies any edit that has not landed yet and records the baseline for the new image. It must be a build command, because files written by deploy commands are thrown away. If the mailbox cannot be listed, it fails the build rather than ship a site that is missing edits. A batch it cannot read is skipped with a warning, so one bad file in the bucket never blocks a code deploy.
- **Deploy commands**: keep what you have, including `php artisan migrate --force`, and add
  ```
  php please stache:refresh
  ```
  With a shared cache, the Stache outlives the deploy, so refresh it to pick up what was committed. Statamic's list of asset files outlives it too, and nothing in Statamic refreshes that, so File Boomerang brings it in line with the disk whenever the Stache is cleared. Images added, renamed or removed in Git show up after the same command.

### 5. Compute

Click the App cluster on the canvas:

- Turn on the **Scheduler**. Every minute it pushes anything a failed save left behind, applies edits other servers mailed, and asks GitHub to land the waiting edits.
- Set the autoscaling strategy to **None** so there is a single replica, and leave **Scale to Zero** off. Each replica has its own disk, and a sleeping app wakes up on the files from its last deploy.

A managed queue is optional. With one, File Boomerang waits out the debounce on the queue. Without one, the scheduler checks every minute.

### 6. Check it

```bash
php artisan boomerang:doctor
```

Then edit an entry in the control panel. After the debounce (two minutes by default) you should see a **File Boomerang** run in the repository's Actions tab, a commit authored by the editor, and a new Cloud deployment.

## GitHub setup

1. Create a fine-grained personal access token: **Settings > Developer settings > Personal access tokens > Fine-grained tokens > Generate new token**. Limit it to the one repository and give it **Actions: Read and write** (GitHub adds Metadata: Read on its own). That is all it needs to start the landing workflow, and it cannot change your code, so a leaked server environment cannot push to your branch. Put it in `FILE_BOOMERANG_GITHUB_TOKEN` on the host, not in a GitHub secret. Set a reminder before it expires; `boomerang:doctor` and the failed job will tell you when it has.
2. Add the four mailbox secrets from the recipe above.
3. To get conflicts as pull requests, turn on **Settings > Actions > General > Allow GitHub Actions to create and approve pull requests**. When it is off, File Boomerang opens an issue that links to the conflict branch instead.
4. If a branch ruleset requires pull requests or status checks on your branch, let GitHub Actions bypass it, or the landing push is rejected. That bypass applies to every workflow in the repository, not only this one.

Commits pushed by a workflow do not start your other workflows. If you want your tests to run on landed content, list them in `landing.workflows` (for example `['tests.yml']`) and add `workflow_dispatch:` to those workflows.

## Other hosts

Anything that can run the scheduler, reach an S3 compatible bucket and deploy from Git will do. Point the mailbox at any bucket with the four variables above, or at a disk you already have in `config/filesystems.php` with `FILE_BOOMERANG_DISK`. The GitHub Action always reads the mailbox from the four `FILE_BOOMERANG_*` secrets, so a named disk must use that same bucket with no root or prefix (not a scoped disk). `boomerang:doctor` warns when it does not. If your provider needs `FILE_BOOMERANG_REGION` or `FILE_BOOMERANG_PATH_STYLE`, or you change `FILE_BOOMERANG_PREFIX`, set them on the host and as repository variables (**Settings > Secrets and variables > Actions > Variables**) so the workflow reads the same mailbox. Run `php artisan boomerang:pull` in your build, right after `composer install`. If your host does not deploy commits pushed by GitHub Actions, add its deploy hook URL as the `FILE_BOOMERANG_DEPLOY_HOOK` repository secret; `{sha}` in the URL is replaced with the landed commit.

If your host runs a hook when a server boots instead of baking files at build time, `php artisan boomerang:catch-up` applies what other servers mailed since the last deploy.

## Config reference

All keys live in `config/file-boomerang.php`.

| Key | Env | Default | What it does |
| --- | --- | --- | --- |
| `enabled` | `FILE_BOOMERANG_ENABLED` | `false` | Turns the whole thing on. |
| `mailbox.disk` | `FILE_BOOMERANG_DISK` | none | Use this disk from `config/filesystems.php` instead of the bucket settings below. |
| `mailbox.bucket` | `FILE_BOOMERANG_BUCKET` | none | Bucket name. |
| `mailbox.endpoint` | `FILE_BOOMERANG_ENDPOINT` | none | S3 compatible endpoint. |
| `mailbox.key` | `FILE_BOOMERANG_ACCESS_KEY_ID` | none | Access key ID. |
| `mailbox.secret` | `FILE_BOOMERANG_SECRET_ACCESS_KEY` | none | Access key secret. |
| `mailbox.region` | `FILE_BOOMERANG_REGION` | `auto` | Region. |
| `mailbox.use_path_style_endpoint` | `FILE_BOOMERANG_PATH_STYLE` | `false` | For providers that need path style URLs, like MinIO. |
| `mailbox.prefix` | `FILE_BOOMERANG_PREFIX` | `file-boomerang` | Folder inside the bucket. |
| `paths` | | Statamic's content folders | Files and folders to track, relative to the project root. |
| `local_asset_containers` | | `true` | Also track every asset container stored on a local disk inside `public`, such as `public/assets`. Add other folders to `paths` yourself. |
| `exclude` | | `.DS_Store`, `.env*` | `fnmatch` patterns to leave out. The Glide cache is always left out. |
| `max_file_size` | | 50 MB | Larger files are skipped and logged. GitHub refuses files over 100 MB. |
| `debounce` | `FILE_BOOMERANG_DEBOUNCE` | `120` | Seconds without a new save before a landing is requested. Keep it under 900. |
| `manifest` | | `storage/framework/file-boomerang.json` | Where each server keeps its baseline. |
| `catch_up.enabled` | | `true` | Apply edits from other servers every minute from the scheduler, before each push, and when an editor opens the control panel. |
| `catch_up.interval` | | `15` | Seconds between the control panel checks. |
| `github.repository` | `FILE_BOOMERANG_GITHUB_REPOSITORY` | none | `owner/repository`. |
| `github.branch` | `FILE_BOOMERANG_GITHUB_BRANCH` | `main` | Branch the edits land on. |
| `github.token` | `FILE_BOOMERANG_GITHUB_TOKEN` | none | Token used to start the landing workflow. |
| `landing.commit_message` | | `Update content from the control panel` | First line of every landing commit. |
| `landing.author` | `FILE_BOOMERANG_AUTHOR_NAME`, `FILE_BOOMERANG_AUTHOR_EMAIL` | `Statamic` | Author for saves made by the scheduler, the queue or the console. |
| `landing.workflows` | | `[]` | Workflow files to run on the branch after a landing. |
| `landing.deploy_hook` | `FILE_BOOMERANG_DEPLOY_HOOK` | none | URL to POST after a landing. `{sha}` becomes the commit. |
| `landing.redispatch_after` | | `30` | Minutes to wait before asking GitHub again when edits are still waiting. |
| `landing.blob_grace` | | `60` | Minutes an unused file is kept in the mailbox before it is cleaned up. |
| `queue.connection` | `FILE_BOOMERANG_QUEUE_CONNECTION` | default | Queue connection for the landing request. |
| `queue.name` | `FILE_BOOMERANG_QUEUE` | default | Queue name for the landing request. |

## Commands

| Command | Where it runs | What it does |
| --- | --- | --- |
| `boomerang:install` | Your machine | Publishes the config, writes the workflow and prints the setup checklist. `--force` overwrites the workflow. |
| `boomerang:pull` | Build | Applies the waiting edits to files Git has not changed since, and records the baseline. Fails if the mailbox cannot be listed. `--seed-only` just records the baseline. |
| `boomerang:push` | Server | Mails any changes on this server now. The scheduler runs it every minute. |
| `boomerang:dispatch` | Server | Asks GitHub to land the waiting edits once the editors have gone quiet, and says what happened. The scheduler runs it every minute. |
| `boomerang:catch-up` | Server | Applies edits other servers mailed since this one last looked. The scheduler runs it every minute. |
| `boomerang:status` | Server | Shows whether it is on, the mailbox, what is waiting and who made it, the last landing request, and files too large to send. Never prints secrets. |
| `boomerang:doctor` | Server | Runs numbered checks and prints a one line fix for each problem. |
| `boomerang:land` | GitHub Actions | Commits the waiting edits and pushes them. `--dry-run` shows what would land without changing anything. |

## FAQ

**Do I need Statamic Pro?**
No. Leave Statamic's own Git automation turned off.

**Is my content stored in the bucket?**
Only until it lands. Batches are deleted once their commit is pushed, and file contents nobody needs any more are cleaned up after `landing.blob_grace` minutes.

**What if a developer changes the same file?**
If the changes touch different lines, they are merged. If they overlap, the branch keeps the developer's version and the editor's whole save goes to a `file-boomerang/conflict-...` branch with a pull request that names the files and the editors.

**What if two editors save the same file?**
Every save is mailed in order, so the last save wins, the same as it does on the site.

**Who is the commit author?**
The editor who made the newest edit in the commit. Other editors in the same commit are added as co-authors. Saves made by the scheduler, a queued job or a console command have no editor, so they use `landing.author`.

**What happens when the Action fails?**
If it fails before the push, the batches stay in the mailbox and the scheduler asks GitHub again after `landing.redispatch_after` minutes. The server keeps showing the edits in the meantime. `boomerang:status` shows what is waiting.

If the run is red after it printed what landed, the content is already in Git. Only a follow-up step failed: the deploy hook, a workflow in `landing.workflows`, or the conflict report. When GitHub refuses both the conflict pull request and the issue, the run fails with a link to the conflict branch, which holds the editors' versions.

**A batch is invalid, or its file is missing from the mailbox. What now?**
`boomerang:land` stops and names the batch, for example `file-boomerang/batches/01J....json`. Check it, then delete that batch from the bucket. The build skips a batch it cannot read with a warning, and a file whose contents are missing from the mailbox is skipped with that reason, so neither blocks a deploy.

**What if the GitHub token expires?**
The landing request fails straight away with a message saying so, and `boomerang:doctor` reports it. Edits keep collecting in the mailbox and land once you set a new token.

**Do queue workers and the scheduler need anything?**
Queue workers on their own cluster have their own disks. Anything they save is mailed like any other edit, and the web server catches up on it within a minute. Run `boomerang:pull` in their build too, so they start with a baseline. A server with no baseline records one and mails nothing, so it can never send a stale copy of the whole site. On Laravel Cloud the scheduler runs on the App cluster and shares the web server's disk.

**Are uploads included?**
Yes, for asset containers on a local disk inside `public`, including the `.meta` files that hold alt text and focal points. Containers on S3 or another cloud disk do not need it.

## Limits

- Run one always-on web replica. Extra replicas pick up each other's edits every minute from the scheduler, before each push and when an editor opens the control panel, but each one needs its own scheduler.
- Files larger than `max_file_size` are skipped, logged and listed by `boomerang:status`.
- Empty folders are not kept, because Git does not track them.
- Symlinks inside tracked paths are ignored, and nothing is ever written through one.
- Files inside tracked paths are committed even if `.gitignore` excludes them. Add patterns to `exclude` for anything that should stay out of Git.
- Only GitHub is supported for landing.
- Statamic revisions are stored in `storage/statamic/revisions` by default, which is not tracked. If you turn revisions on, set `STATAMIC_REVISIONS_PATH=content/revisions`.

## License

File Boomerang is open source software released under the [MIT license](LICENSE.md).
