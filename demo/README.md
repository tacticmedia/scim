# Demo

Two Symfony applications show the SCIM server with a client. They are development tooling. They do not ship.

| Directory | Application | Port |
| --- | --- | --- |
| `identity-provider/` | A fake identity provider (IdP). It keeps a small directory of users and groups. It provisions the directory over SCIM with the requests of Microsoft Entra ID, Okta or Keycloak. See [its README](identity-provider/README.md). | 8000 |
| `service-provider/` | A SCIM service provider (SP) that uses `tacticmedia/scim-server-bundle`. It shows the users and groups that it gets. See [its README](service-provider/README.md). | 8001 |

## Run the demo

Run the commands from the repository root. Install the root dependencies with `composer install` first.

```
make demo
```

The command:

1. Deletes `identity-provider/var/identity-provider.sqlite` and `service-provider/var/service-provider.sqlite`.
2. Creates the two database schemas.
3. Seeds the IdP directory with 6 users and 2 groups.
4. Starts the SP on http://127.0.0.1:8001 and the IdP on http://127.0.0.1:8000 with `php -S`.

Ctrl-C stops the two servers.

Then:

1. Open http://127.0.0.1:8000 and click "Provision everything".
2. Open http://127.0.0.1:8001. The page shows the provisioned users and groups.
3. Change, disable or delete a user or a group in the IdP. The IdP provisions each change immediately. Reload the SP
   page.
4. Open "Provisioning log" in the IdP to see each SCIM request and response.
5. Open "Settings" in the IdP to select the dialect: `entra`, `entra-flag`, `okta` or `keycloak`.
6. Open "Import" in the IdP to read the users of the SP page by page. With the `keycloak` dialect and a page size of 2,
   the IdP follows the RFC 9865 cursors of the SP.

To run one application alone, read its README.

## End-to-end test

```
vendor/bin/phpunit tests/demo/EndToEnd
```

`composer test` also runs it. The test does not use the demo databases or the ports 8000 and 8001. For each dialect
(`entra`, `entra-flag`, `okta`, `keycloak`), the test:

1. Creates a temporary SQLite file and its schema with the SP console.
2. Starts the SP with `php -S` on a free port and waits until the SP answers.
3. Boots the IdP kernel in the environment `e2e`. This environment uses the real HTTP client. Its database is in
   `identity-provider/var/cache/e2e/`.
4. Seeds the IdP and provisions everything. Then it renames a user, disables a user, changes a manager, removes and
   adds a group member, renames a group, deletes a user, deletes a group and provisions everything again. It
   provisions after each change, as the IdP UI does.
5. After each step, compares the SP database and the SCIM responses of the SP with the expected state. Okta and
   Keycloak do not delete a user, they deactivate the user. Keycloak sends no manager.
6. Imports the users of the SP in pages of 2: by cursor for `keycloak`, by `startIndex` for the other dialects.

The test stops the SP and deletes its files. It takes approximately 8 seconds.
