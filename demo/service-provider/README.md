# Demo service provider

This application is a SCIM service provider (SP). It installs `tacticmedia/scim-server-bundle` from Packagist and
uses it as [the bundle documentation](../../scim-server-bundle/docs/index.md) tells an application to use it. An identity
provider, for example the fake identity provider in `demo/identity-provider/`, creates, changes and deletes its users
and groups through SCIM.

The page at `/` shows the users and groups that the SP has: name, user name, email, active state, manager, groups and
department of each user, and the members of each group. The page only shows data. You change the data in the identity
provider.

The SP is development tooling. It does not ship.

## Run it

Run the commands from the repository root. [The demo README](../README.md) starts the SP together with the identity
provider. To start the SP alone:

```
docker compose --file demo/compose.yaml up --build service-provider
```

Each start of the container creates the database again. Open http://127.0.0.1:8001, then provision from the identity
provider. See `demo/identity-provider/README.md`.

## Deploy

`docker build demo/service-provider` makes an image that serves the SP with `php -S` on port 8001 in the environment
`prod`. Set `APP_SECRET`, because the value in `.env` is public, and `SCIM_TOKEN`.

Without Docker, `composer install` in `demo/service-provider/` installs the SP. Its `bin/console` and
`public/index.php` use only that `vendor/`.

## SCIM endpoint

| Item | Value |
| --- | --- |
| SCIM base URL | `http://127.0.0.1:8001/scim/v2` |
| Token | `demo-token` |
| Resource types | `User` at `/Users`, `Group` at `/Groups` |

The SP accepts the token as `Authorization: Bearer demo-token` (Entra ID, Keycloak) and as `Authorization: demo-token` (Okta).

Example:

```
curl -H 'Authorization: Bearer demo-token' http://127.0.0.1:8001/scim/v2/Users
```

## Configuration

The environment variables give the configuration:

| Variable | Default | Meaning |
| --- | --- | --- |
| `DATABASE_PATH` | `demo/service-provider/var/service-provider.sqlite` | The SQLite file. Use an absolute path: `php -S` runs the SP in `demo/service-provider/public/`. |
| `SCIM_TOKEN` | `demo-token` | The token that the SP accepts. It is the default token of the identity provider. |

Example: `SCIM_TOKEN=other-token docker compose --file demo/compose.yaml up --build`. Compose gives the token to the
identity provider too.

PHP must use `date.timezone = UTC`. This is the PHP default.

## What the SP maps

| Resource type | Attributes |
| --- | --- |
| `User` | `id` (UUID v7), `externalId` (unique), `userName` (unique), `name.givenName`, `name.familyName`, `name.formatted` (read-only, computed), `displayName`, `emails[type eq "work"].value`, `phoneNumbers[type eq "mobile"].value`, `active`, `title`, `password` (stored as a hash, never returned), enterprise `employeeNumber`, `department` and `manager`, `groups` (read-only), `meta.created`, `meta.lastModified`, `meta.version` |
| `Group` | `id` (UUID v7), `externalId` (unique), `displayName` (unique), `members` (users and groups), `meta.created`, `meta.lastModified`, `meta.version` |

A duplicate value of a unique attribute gives 409 `uniqueness`. The SP drops other attributes in a request body. A
PATCH of another path gives 400 `invalidPath`.

The SP shows these bundle features:

- Validation: `User` has `validationGroups: ['scim']`, `Assert\NotBlank` on `userName` and `Assert\Email` on the
  work email. A violation gives 400 `invalidValue`, and the `detail` names the SCIM path, for example
  `Attribute "emails[type eq "work"].value": This value is not a valid email address.`
- Computed attribute: `name.formatted` maps `User::getFormattedName()`. Its `#[ScimDqlExpression]` makes it filterable
  and sortable: `filter=name.formatted eq "Ada Lovelace"`, `sortBy=name.formatted`.
- ETags: `meta.version` maps a Doctrine `#[ORM\Version]` column. A response has `ETag: W/"<version>"`. An `If-Match`
  with an old version gives 412.
- Soft delete: `User::$deletedAt` has `#[ScimSoftDelete]`, so a DELETE sets it. The SCIM API and the directory page
  then leave the user out, also as a manager and as a group member. A POST with the same `userName` revives the user.
- Cursor pagination (RFC 9865): `GET /scim/v2/Users?cursor&count=2`, then the `nextCursor` of each page. The "Import"
  of the identity provider with the `keycloak` dialect pages this way.

`bin/console debug:scim` shows the mappings:

```
docker compose --file demo/compose.yaml exec service-provider bin/console debug:scim
```

## Tests

```
vendor/bin/phpunit tests/demo/ServiceProvider
```

The tests send SCIM requests through the firewall and read the directory page. Each test run uses its own SQLite file
in the kernel cache directory and its own token.

## Code

The code is in the github.com/tacticmedia/scim monorepo, directory `demo/service-provider/`. The tests are in
`tests/demo/ServiceProvider/`.
