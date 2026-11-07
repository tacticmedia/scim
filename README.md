# tacticmedia/scim

The development monorepo of two Composer packages. Packagist does not list this repository.

| Package | Directory | Split repository |
| --- | --- | --- |
| `tacticmedia/scim-server` | `scim-server/` | [tacticmedia/scim-server](https://github.com/tacticmedia/scim-server) |
| `tacticmedia/scim-server-bundle` | `scim-server-bundle/` | [tacticmedia/scim-server-bundle](https://github.com/tacticmedia/scim-server-bundle) |

A GitHub Actions workflow pushes each package directory to its read-only split repository. Packagist reads only
the split repositories ([docs/release.md](docs/release.md)).

## Start

Requirements: PHP 8.5 with Xdebug, Composer 2. `make ci` and the demo also need Docker.

```bash
composer install
make qa-check
```

[AGENTS.md](AGENTS.md) gives the layout, the rules and the commands. [docs/ci.md](docs/ci.md) describes CI.

## Demo

`demo/` contains a fake identity provider and a service provider that uses the bundle. The identity provider sends
the SCIM requests of Microsoft Entra ID, Okta and Keycloak. Each is a standalone Symfony application that installs
its dependencies with Composer and deploys alone.

```bash
docker compose --file demo/compose.yaml up --build
```

The service provider starts on http://127.0.0.1:8001 and the identity provider on http://127.0.0.1:8000. Ctrl-C
stops both. [demo/README.md](demo/README.md) tells how to use the demo and how to run its end-to-end test.
