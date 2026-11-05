# tacticmedia/scim

The development monorepo of two Composer packages. Packagist does not list this repository.

| Package | Directory | Split repository |
| --- | --- | --- |
| `tacticmedia/scim-server` | `scim-server/` | [tacticmedia/scim-server](https://github.com/tacticmedia/scim-server) |
| `tacticmedia/scim-server-bundle` | `scim-server-bundle/` | [tacticmedia/scim-server-bundle](https://github.com/tacticmedia/scim-server-bundle) |

A GitHub Actions workflow pushes each package directory to its read-only split repository. Packagist reads only
the split repositories ([docs/release.md](docs/release.md)).

## Start

Requirements: PHP 8.5, Composer 2. `make ci` also needs Docker.

```bash
composer install
make qa-check
```

[AGENTS.md](AGENTS.md) gives the layout, the rules and the commands. [docs/ci.md](docs/ci.md) describes CI.
