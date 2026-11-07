# Demo

Two Symfony applications show the SCIM server with a client. They are development tooling. They do not ship as
packages.

| Directory | Application | Port |
| --- | --- | --- |
| `identity-provider/` | A fake identity provider (IdP). It keeps a small directory of users and groups. It provisions the directory over SCIM with the requests of Microsoft Entra ID, Okta or Keycloak. See [its README](identity-provider/README.md). | 8000 |
| `service-provider/` | A SCIM service provider (SP) that uses `tacticmedia/scim-server-bundle`. It shows the users and groups that it gets. See [its README](service-provider/README.md). | 8001 |

Each application is a standalone Composer project with its own `composer.json`, `composer.lock`, `.env` and
`Dockerfile`, and deploys alone. Composer installs the packages from Packagist: the SP image contains the release
that `service-provider/composer.lock` names, not the working copy of `scim-server/` and `scim-server-bundle/`.

## Run the demo

Requirements: Docker with Compose. Run the commands from the repository root.

```
docker compose --file demo/compose.yaml up --build
```

The command:

1. Builds an image for each application from its directory: `composer install` from its `composer.lock`, then the
   cache of the environment `prod`.
2. Starts the SP on http://127.0.0.1:8001 and the IdP on http://127.0.0.1:8000. The IdP sends its SCIM requests to
   `http://service-provider:8001/scim/v2`.

Each start of a container deletes the SQLite database of its application and creates it again. The IdP then seeds its
directory with 6 users and 2 groups. Ctrl-C stops the two containers. `docker compose --file demo/compose.yaml down`
removes them.

The shell variables `SCIM_TOKEN` and `SCIM_DIALECT` go to the containers when they are set, for example
`SCIM_DIALECT=okta docker compose --file demo/compose.yaml up --build`.

Then:

1. Open http://127.0.0.1:8000 and click "Provision everything".
2. Open http://127.0.0.1:8001. The page shows the provisioned users and groups.
3. Change, disable or delete a user or a group in the IdP. The IdP provisions each change immediately. Reload the SP
   page.
4. Open "Provisioning log" in the IdP to see each SCIM request and response.
5. Open "Settings" in the IdP to select the dialect: `entra`, `entra-flag`, `okta` or `keycloak`.
6. Open "Import" in the IdP to read the users of the SP page by page. With the `keycloak` dialect and a page size of 2,
   the IdP follows the RFC 9865 cursors of the SP.

A console command runs in its container, for example:

```
docker compose --file demo/compose.yaml exec identity-provider bin/console idp:provision
```

To run or deploy one application alone, read its README.

## End-to-end test

```
vendor/bin/phpunit tests/demo/EndToEnd
```

`composer test` also runs it. The test does not use Docker, the demo databases or the ports 8000 and 8001. It uses
the root `vendor/`, thus the working copy of the packages. For each dialect (`entra`, `entra-flag`, `okta`,
`keycloak`), the test:

1. Creates a temporary SQLite file, boots the SP kernel in the test process and creates the schema.
2. Starts the SP with `php -S` on a free port and waits until the SP answers. The front controller is
   `tests/demo/EndToEnd/public/index.php`, which loads the root autoloader.
3. Boots the IdP kernel in the environment `e2e`. This environment uses the real HTTP client. Its database is in
   `identity-provider/var/cache/e2e/`.
4. Seeds the IdP and provisions everything. Then it renames a user, disables a user, changes a manager, removes and
   adds a group member, renames a group, deletes a user, deletes a group and provisions everything again. It
   provisions after each change, as the IdP UI does.
5. After each step, compares the SP database and the SCIM responses of the SP with the expected state. Okta and
   Keycloak do not delete a user, they deactivate the user. Keycloak sends no manager.
6. Imports the users of the SP in pages of 2: by cursor for `keycloak`, by `startIndex` for the other dialects.

The test stops the SP and deletes its files. It takes approximately 8 seconds.
