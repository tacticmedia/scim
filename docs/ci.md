# Continuous integration

`.github/workflows/ci.yaml` runs four jobs on each pull request, each push to `main` and each manual dispatch.

| Job | Runs on | Runs |
| --- | --- | --- |
| `qa` | `ubuntu-latest` with setup-php | actionlint over `.github/workflows/`, `composer audit`, then the composer scripts `lint`, `dependencies`, `rector:check`, `cs:check` and `stan`. |
| `tests` | `ubuntu-latest` with setup-php and Xdebug | `composer install`, then `composer coverage`: PHPUnit with a Clover report, then `bin/coverage`, which fails below 100 percent line coverage. The tests use in-memory SQLite. |
| `databases` | `ubuntu-latest` with setup-php, and one database service per matrix entry: PostgreSQL 18.6, MySQL 9.7.4 (LTS), MySQL 26.10.0 (Innovation), MariaDB 12.3.3 (LTS), MariaDB 13.0.2 | `composer install`, then `composer test` with `DATABASE_URL` set to the service. The data directory of the service is on tmpfs. No coverage. |
| `lowest` | `ubuntu-latest` with setup-php | Sets each root `require-dev` constraint of a package that `scim-server/composer.json` or `scim-server-bundle/composer.json` requires or conflicts with to `*`, then `composer update --prefer-lowest --prefer-stable`, then `composer test`. The packages and the dev tools thus set the floors, not the root constraints that `bump-after-update` raises. |

All jobs install the PHP extensions of the `ext-*` entries in `composer.json`. The `databases` job also installs
the PDO driver of its database from the matrix. `composer.json` does not list `ext-pdo_pgsql` and `ext-pdo_mysql`,
so that `composer install` works without them.

The gates `lint`, `dependencies`, `rector:check`, `cs:check`, `stan` and `coverage` are composer scripts, so
`make qa-check` runs them too. actionlint and `composer audit` run only in the workflow, and `make ci` runs them locally. Add each
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
      same workspace and daemon, which GitHub never does. Adds `max-parallel: 1` to each `strategy:`
      ([Where it differs from GitHub](#where-it-differs-from-github)).
   4. Runs act for that job with the event in `.github/act/event.json`, a push to `main`.
4. Prints the jobs that passed and failed, and exits non-zero if any failed.

`run.sh` reads `HEAD` for the snapshot, so the repository needs at least one commit.

Each job's output is in `var/ci/logs/<job>.log`.

The act flags are in `.github/act/actrc`, copied into the runner image. `--env-file /dev/null` stops act from
loading a `.env` file into every step.

## Running the tests on one database

The test kernel reads `DATABASE_URL`. Without it, the tests use in-memory SQLite. Each test drops every table of
the database first. Start the server as the `databases` job does, then run the tests:

```bash
docker run --detach --rm --name scim-postgres --publish 127.0.0.1:5432:5432 --tmpfs /var/lib/postgresql \
  --env POSTGRES_USER=scim --env POSTGRES_PASSWORD=scim --env POSTGRES_DB=scim \
  --env POSTGRES_INITDB_ARGS='--locale-provider=builtin --builtin-locale=C.UTF-8' postgres:18.6
DATABASE_URL='postgresql://scim:scim@127.0.0.1:5432/scim?serverVersion=18.6&charset=utf8' composer test

docker run --detach --rm --name scim-mysql --publish 127.0.0.1:3306:3306 --tmpfs /var/lib/mysql \
  --env MYSQL_ROOT_PASSWORD=scim --env MYSQL_DATABASE=scim mysql:9.7.4
DATABASE_URL='mysql://root:scim@127.0.0.1:3306/scim?serverVersion=9.7.4&charset=utf8mb4' composer test
```

For MariaDB, use the same `MYSQL_*` variables and a `serverVersion` that ends in `-MariaDB`, for example
`12.3.3-MariaDB`. The `databases` job in `.github/workflows/ci.yaml` has the URL of each entry. A server of
your own needs the settings of [Database notes](../scim-server-bundle/docs/index.md#database-notes): the builtin
`C.UTF-8` locale on PostgreSQL, and a MySQL or MariaDB server that has `utf8mb4_0900_bin`.

`make ci JOB=databases` runs all entries through act.

## Where it differs from GitHub

- **Architecture.** The runner has the host's architecture, arm64 on Apple silicon. GitHub's `ubuntu-latest` is
  amd64.
- **Runner image.** `catthehacker/ubuntu:act-26.04-dev` is a subset of GitHub's `ubuntu-26.04`. A step that relies
  on a tool GitHub preinstalls and the subset lacks fails locally only.
- **Job order.** The jobs run one after the other. GitHub runs them in parallel.
- **Matrix entries.** The entries of a matrix run one after the other in one workspace. GitHub runs them in
  parallel on separate machines. Do not set `max-parallel` in the workflow: the key would then appear twice.
- **Caches.** `actions/cache`, also inside `ramsey/composer-install`, goes to act's cache server, which keeps the
  entries in the runner's `act` volume between runs. The keys are GitHub's, so a changed `composer.lock` misses
  locally when it misses on GitHub. GitHub also evicts caches unused for 7 days; the runner never does.
- **Token.** act has no GitHub token, so the actions call the GitHub API anonymously.

## Maintenance

Nothing updates these pins automatically:

| Pin | Where | Keep it at |
| --- | --- | --- |
| `docker:<version>-dind` | `.github/act/Dockerfile` | The `docker-ce` version in the toolset of the image that `ubuntu-latest` uses, now the [ubuntu 26.04 toolset](https://github.com/actions/runner-images/blob/main/images/ubuntu/toolsets/toolset-2604.json). When that version has no `dind` image, the nearest patch release that has one. |
| `catthehacker/ubuntu:act-<version>` | `.github/act/actrc` | The Ubuntu version that `ubuntu-latest` uses, now 26.04. catthehacker publishes it only as `act-26.04-dev`. Use `act-26.04` when it exists. |
| `ACT_VERSION` | `.github/act/Dockerfile` | The latest act release. |
| `rhysd/actionlint:<version>` | `.github/act/run.sh` and `.github/workflows/ci.yaml` | The same release in both. |
| `postgres:<version>` | `databases` matrix in `.github/workflows/ci.yaml` | The latest PostgreSQL release. |
| `mysql:<version>` | `databases` matrix in `.github/workflows/ci.yaml` | The latest LTS release and the latest Innovation release. |
| `mariadb:<version>` | `databases` matrix in `.github/workflows/ci.yaml` | The latest LTS release and the latest rolling release. |

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
