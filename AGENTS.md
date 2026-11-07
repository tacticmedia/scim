# AGENTS.md

This is the development monorepo of two Composer packages: `tacticmedia/scim-server`, a SCIM 2.0 server library,
and `tacticmedia/scim-server-bundle`, its Symfony bundle. The support floor is PHP 8.5 and Symfony 8.1.

## Layout

| Path | Contents |
| --- | --- |
| `scim-server/` | The library, namespace `TacticMedia\ScimServer\`. It ships as `tacticmedia/scim-server`. |
| `scim-server-bundle/` | The bundle, namespace `TacticMedia\ScimServerBundle\`. It ships as `tacticmedia/scim-server-bundle`. |
| `tests/scim-server/` | Library tests, namespace `TacticMedia\ScimServer\Tests\`. |
| `tests/scim-server-bundle/` | Bundle tests, namespace `TacticMedia\ScimServerBundle\Tests\`. |
| `tests/scim-server-bundle/Fixtures/TestKernel.php` | The smallest kernel that loads the bundle. `bin/console`, PHPStan, Rector and the functional tests use it. Its project directory is the repository root, so its cache is in `var/cache/test/`. It reads `DATABASE_URL`, default in-memory SQLite, see [docs/ci.md](docs/ci.md#running-the-tests-on-one-database). |
| `demo/identity-provider/` | The demo identity provider, namespace `TacticMedia\ScimDemo\IdentityProvider\`. It provisions over SCIM as Entra ID, Okta and Keycloak do. |
| `demo/service-provider/` | The demo service provider, namespace `TacticMedia\ScimDemo\ServiceProvider\`. It uses the bundle. |
| `tests/demo/` | Demo tests, namespace `TacticMedia\ScimDemo\Tests\`. `EndToEnd/` starts the service provider with `php -S` and provisions it from the identity provider kernel in the environment `e2e`. |
| `bin/dependencies` | The dependency gate, see [Dependency gate](#dependency-gate). |
| `.github/workflows/split.yml` | Pushes each package directory to its split repository, see [docs/release.md](docs/release.md). |

Everything outside the two package directories is development tooling and never ships.

## Package directory rules

The split repository of a package contains only its package directory, and Packagist reads only the split
repositories. Thus:

- A package directory contains only what ships: `src/`, `config/` (bundle, only when it has configuration files),
  `docs/` (bundle), `skills/` and `INSTRUCTIONS.md` (bundle, see [Agent files](#agent-files)), `composer.json`,
  `LICENSE` and `README.md`. No tests, no tool configuration, no `composer.lock`, no `vendor/`.
- A package `composer.json` contains `name`, `description`, `license`, `type`, `keywords` if useful, `require`,
  `autoload` and, in the bundle, `extra.ai-mate`. No `require-dev`, `autoload-dev`, `scripts`, `config` or
  `minimum-stability`.
- The `type` is `library` or `symfony-bundle`.
- The `require` of a package lists what its `src/` needs to load and run, and nothing more.
  `composer dependencies` checks the packages that `src/` names.
- The bundle requires `tacticmedia/scim-server` at the current line, for example `^0.1`.
- The library never uses the bundle.
- The package `README.md` tells a consumer how to install and register the package. It tells a contributor that
  the code is in the github.com/tacticmedia/scim monorepo.
- Do not move or rename a package directory. [docs/release.md](docs/release.md) gives the reason.

## Agent files

The bundle ships files for the coding agents of its consumers:

| Path | Contents |
| --- | --- |
| `scim-server-bundle/skills/scim-integration/SKILL.md` | An [Agent Skill](https://agentskills.io/specification): the integration steps. |
| `scim-server-bundle/INSTRUCTIONS.md` | Agent instructions. |
| `scim-server-bundle/docs/identity-providers.md` | What each identity provider sends, and what to map. It lists its vendor sources. |

- `extra.ai-mate` in the bundle `composer.json` declares the skills and the instructions to
  [Symfony AI Mate](https://symfony.com/doc/current/ai/components/mate/creating-extensions.html). `mate discover`
  copies each skill directory to `.agents/skills/mate-<name>/` and `.claude/skills/` of the consumer. It merges
  `INSTRUCTIONS.md` into `mate/AGENT_INSTRUCTIONS.md`, which the agent reads in each session. Keep it short.
- Mate copies only the skill directory. A Markdown link in `SKILL.md` must point inside it. Name other files by
  their path below `vendor/tacticmedia/scim-server-bundle/`, in a code span.
- When a change makes a step, a rule or an example of the skill wrong, change the skill in the same commit.

## Commands

| Command | Result |
| --- | --- |
| `composer test`, `make test` | PHPUnit. |
| `composer coverage` | PHPUnit with Xdebug coverage, then `bin/coverage`: it fails below 95 percent line coverage of the package and demo sources. |
| `composer qa`, `make qa` | Rector, php-cs-fixer, the linters, the dependency gate, PHPStan and the coverage gate. It changes files. |
| `composer qa:check`, `make qa-check` | The same gates. It changes no file. |
| `make ci` | The CI workflow through act, see [docs/ci.md](docs/ci.md). |
| `make demo` | Deletes and creates the demo databases, seeds the identity provider, then starts the service provider on port 8001 and the identity provider on port 8000. Ctrl-C stops both. See [demo/README.md](demo/README.md). |
| `bin/console` | The console of the test kernel, environment `test`, debug on. |

The gates one at a time: `composer lint`, `composer dependencies`, `composer rector:check`, `composer cs:check`,
`composer stan`.

## Dependency gate

`composer dependencies` runs `bin/dependencies`. For each path repository of the root `composer.json`, the
script copies the package directory to `var/dependencies/<directory>/`, installs the copy, and runs
composer-dependency-analyser against it. The analyser uses the vendor directory of the `composer.json` that it
analyses, so it cannot use the root `vendor/`.

- The analyser configuration of a package is `composer-dependency-analyser.<directory>.php` at the root. Never put
  it in a package directory.
- `composer-dependency-analyser.scim-server-bundle.php` also scans the bundle `config/`, because its files ship and
  use the routing and DI components. It ignores the DI configurator functions (`service()`, `param()`), because
  `ContainerConfigurator.php` declares them, not an autoload file. It ignores unknown `Doctrine\` classes:
  `doctrine/orm` and `doctrine/doctrine-bundle` are in the `suggest` of the bundle, not in its `require`, so the
  installed copy does not have them.
- `composer-dependency-analyser.scim-server.php` ignores unknown `Psr\Http\` and `Symfony\Bridge\PsrHttpMessage\`
  classes: the PSR-15 handler uses them, and their packages are in the `suggest` of the library.
- The analyser fails while an ignore matches nothing. When `src/` no longer names a class of an ignored namespace,
  remove that ignore.

## Composer

- The root `require` contains only `php` and the two path packages. The path packages have the constraint `@dev`.
  `bump-after-update` and `composer normalize` do not change `@dev`, and `@dev` matches every version.
- `@dev` has no upper bound. Thus `composer lint` validates the root with `--no-check-all`. That option disables
  only the unbound-constraint and exact-constraint checks, and Composer applies them only to `require`.
- Add a development tool with `composer require --dev <package>`. Add no other package to the root `require`.
- The root `conflict` keeps each Symfony component below 8.2, so Rector and the tests use the support floor.
  Composer does not accept a wildcard package name there. When a new Symfony component enters `composer.lock`,
  add it with `>=8.2`; `composer lint` runs `bin/symfony-pin`, which fails until you do.
- A Symfony constraint in a package `require` is `^8.1`. A component with a security advisory that affects 8.1
  starts at the first fixed patch release, for example `symfony/http-foundation` `^8.1.11`. Do not lower such a floor.

## Version bump

Both packages always have the same version.

1. In the root `composer.json`, set the `versions` option of both path repositories to the new line, for example
   `0.2.x-dev`.
2. In `scim-server-bundle/composer.json`, set the `tacticmedia/scim-server` constraint to the new line, for
   example `^0.2`.
3. Run `composer update`.
4. Run `make qa-check`.

[docs/release.md](docs/release.md) gives the release procedure.

## Conventions

- Keep code comments to the minimum. Before you write a comment, try to make the code self-explanatory. Write
  `$fileSizeBytes`, not `$fileSize` with the comment `// bytes`.
- All PHP code passes PHPStan level 8.
- Tests: a unit test for a simple concept, a functional test for code that needs the service container or a
  database, and a Panther end-to-end test for front-end behaviour that relies on JavaScript. Base test cases on
  `Symfony\Bundle\FrameworkBundle\Test\KernelTestCase` and its subclasses. A library test extends
  `KernelTestCase` and never boots the kernel. Never mock the dependency injection container. Give each test a
  `#[TestDox]` that is as short as possible without loss of information.
- Do not use `-v` or `--verbose` with PHPUnit. Use `--debug`.
- Use current language features that the support floor permits: PHP 8.5 and Symfony 8.1.
