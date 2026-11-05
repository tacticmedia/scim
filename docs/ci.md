# Continuous integration

`.github/workflows/ci.yaml` runs two jobs on each pull request, each push to `main` and each manual dispatch.

| Job | Runs on | Runs |
| --- | --- | --- |
| `qa` | `ubuntu-latest` with setup-php | actionlint over `.github/workflows/`, `composer audit`, then the composer scripts `lint`, `dependencies`, `rector:check`, `cs:check` and `stan`. |
| `tests` | `ubuntu-latest` with setup-php | `composer install`, then `composer test`. |

Both jobs install the PHP extensions of the `ext-*` entries in `composer.json`.

The gates `lint`, `dependencies`, `rector:check`, `cs:check` and `stan` are composer scripts, so `make qa-check`
runs them too. actionlint and `composer audit` run only in the workflow, and `make ci` runs them locally. Add each
new gate to `composer.json` as a script. Then call the script from the workflow.

`.github/workflows/split.yml` is not part of CI. It pushes the packages to their split repositories
([Release](release.md)), and `make ci` does not run it.

## Running the workflow locally

```bash
make ci                 # every job
make ci JOB=tests       # one job
```

Nothing is ready to push until `make ci` passes. It runs `.github/act/run.sh` on the host. The repository
directory name, `ACT_PROJECT`, names everything below, so checkouts in directories of different names get
separate runners.

1. actionlint over `.github/workflows/`.
2. Starts the runner, compose project `<repository>-act` from `.github/act/compose.yaml`: one privileged
   `docker:dind` container with [act](https://github.com/nektos/act) installed. The jobs get a Docker daemon of
   their own, so they do not change the host daemon.
3. For each job of `.github/workflows/ci.yaml`, in workflow order:
   1. Copies a snapshot of the working tree into the workspace, a volume at GitHub's path
      `/home/runner/work/<repository>/<repository>`. The snapshot is what `git add --all` would commit:
      untracked files are in it, ignored files are not. The snapshot does not change the index or HEAD of the
      checkout. The snapshot gets the checkout's `origin` remote when the checkout has one.
   2. Removes every container, volume and network from the runner's daemon. Images stay.
   3. Deletes the `needs:` lines from the snapshot's workflow. act runs a job's needs as part of that job, in the
      same workspace and daemon, which GitHub never does.
   4. Runs act for that job with the event in `.github/act/event.json`, a push to `main`.
4. Prints the jobs that passed and failed, and exits non-zero if any failed.

`run.sh` reads `HEAD` for the snapshot, so the repository needs at least one commit.

Each job's output is in `var/ci/logs/<job>.log`.

The act flags are in `.github/act/actrc`, copied into the runner image. `--env-file /dev/null` stops act from
loading a `.env` file into every step.

## Where it differs from GitHub

- **Architecture.** The runner has the host's architecture, arm64 on Apple silicon. GitHub's `ubuntu-latest` is
  amd64.
- **Runner image.** `catthehacker/ubuntu:act-24.04` is a subset of GitHub's `ubuntu-24.04`. A step that relies
  on a tool GitHub preinstalls and the subset lacks fails locally only.
- **Job order.** The jobs run one after the other. GitHub runs them in parallel.
- **Caches.** `actions/cache`, also inside `ramsey/composer-install`, goes to act's cache server, which keeps the
  entries in the runner's `act` volume between runs. The keys are GitHub's, so a changed `composer.lock` misses
  locally when it misses on GitHub. GitHub also evicts caches unused for 7 days; the runner never does.
- **Token.** act has no GitHub token, so the actions call the GitHub API anonymously.

## Maintenance

Nothing updates these pins automatically:

| Pin | Where | Keep it at |
| --- | --- | --- |
| `docker:<version>-dind` | `.github/act/Dockerfile` | The `docker-ce` version in the toolset of the image that `ubuntu-latest` uses, now the [ubuntu 24.04 toolset](https://github.com/actions/runner-images/blob/main/images/ubuntu/toolsets/toolset-2404.json). |
| `catthehacker/ubuntu:act-<version>` | `.github/act/actrc` | The Ubuntu version that `ubuntu-latest` uses, now 24.04. |
| `ACT_VERSION` | `.github/act/Dockerfile` | The latest act release. |
| `rhysd/actionlint:<version>` | `.github/act/run.sh` and `.github/workflows/ci.yaml` | The same release in both. |

`make ci` rebuilds the runner image on every run, so a changed pin applies to the next run.

A failed job's containers stay in the runner's daemon until the next job starts. `run.sh` exports
`ACT_PROJECT`, which `.github/act/compose.yaml` requires, so set it when you call Compose yourself:

```bash
export ACT_PROJECT=$(basename "$(git rev-parse --show-toplevel)")
docker compose -f .github/act/compose.yaml exec runner docker ps --all
docker compose -f .github/act/compose.yaml exec runner docker logs <container>
```

The runner stays up after a run, so the next one skips the start:

```bash
docker compose -f .github/act/compose.yaml stop            # stops it
docker compose -f .github/act/compose.yaml down --volumes  # removes it with its images, caches and workspace
```

After `down --volumes` the next `make ci` starts cold.
