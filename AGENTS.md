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
| `tests/scim-server-bundle/Fixtures/TestKernel.php` | The smallest kernel that loads the bundle. `bin/console`, PHPStan, Rector and the functional tests use it. Its project directory is the repository root, so its cache is in `var/cache/test/`. |
| `bin/dependencies` | The dependency gate, see [Dependency gate](#dependency-gate). |
| `.github/workflows/split.yml` | Pushes each package directory to its split repository, see [docs/release.md](docs/release.md). |

Everything outside the two package directories is development tooling and never ships.

## Package directory rules

The split repository of a package contains only its package directory, and Packagist reads only the split
repositories. Thus:

- A package directory contains only what ships: `src/`, `config/` (bundle, only when it has configuration files),
  `docs/index.md` (bundle), `composer.json`, `LICENSE` and `README.md`. No tests, no tool configuration, no
  `composer.lock`, no `vendor/`.
- A package `composer.json` contains `name`, `description`, `license`, `type`, `keywords` if useful, `require`
  and `autoload`. No `require-dev`, `autoload-dev`, `scripts`, `config` or `minimum-stability`.
- The `type` is `library` or `symfony-bundle`.
- The `require` of a package lists what its `src/` needs to load and run, and nothing more.
  `composer dependencies` checks the packages that `src/` names.
- The bundle requires `tacticmedia/scim-server` at the current line, for example `^1.0`.
- The library never uses the bundle.
- The package `README.md` tells a consumer how to install and register the package. It tells a contributor that
  the code is in the github.com/tacticmedia/scim monorepo.
- Do not move or rename a package directory. [docs/release.md](docs/release.md) gives the reason.

## Commands

| Command | Result |
| --- | --- |
| `composer test`, `make test` | PHPUnit. |
| `composer qa`, `make qa` | Rector, php-cs-fixer, the linters, the dependency gate, PHPStan and PHPUnit. It changes files. |
| `composer qa:check`, `make qa-check` | The same gates. It changes no file. |
| `make ci` | The CI workflow through act, see [docs/ci.md](docs/ci.md). |
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
- `composer-dependency-analyser.scim-server-bundle.php` ignores two kinds of unused requirement:
  `tacticmedia/scim-server`, until the bundle first uses the library, and `symfony/config` and
  `symfony/dependency-injection`, which `AbstractBundle` needs although `symfony/http-kernel` does not require
  them.
- The analyser fails while an ignore matches nothing. When `src/` names a class of an ignored package, remove that
  ignore.

## Composer

- The root `require` contains only `php` and the two path packages. The path packages have the constraint `@dev`.
  `bump-after-update` and `composer normalize` do not change `@dev`, and `@dev` matches every version.
- `@dev` has no upper bound. Thus `composer lint` validates the root with `--no-check-all`. That option disables
  only the unbound-constraint and exact-constraint checks, and Composer applies them only to `require`.
- Add a development tool with `composer require --dev <package>`. Add no other package to the root `require`.

## Version bump

Both packages always have the same version.

1. In the root `composer.json`, set the `versions` option of both path repositories to the new line, for example
   `1.1.x-dev`.
2. In `scim-server-bundle/composer.json`, set the `tacticmedia/scim-server` constraint to the new line, for
   example `^1.1`.
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
