# Fake identity provider

This application is a fake identity provider (IdP). It keeps a small directory of users and groups. It provisions
the directory to a SCIM service provider (SP) over HTTP. It sends the requests that Microsoft Entra ID, Okta and
Keycloak with the scim-outbound-for-keycloak plugin send, with their quirks. The demo SP uses `tacticmedia/scim-server-bundle`. The IdP shows that the library accepts these
requests.

The IdP is development tooling. It does not ship.

## Run it

Run the commands from the repository root. [The demo README](../README.md) starts the IdP together with the SP. To
start the IdP alone:

```
docker compose --file demo/compose.yaml up --build identity-provider
```

Each start of the container creates the database `var/identity-provider.sqlite` again and seeds the sample directory:
6 users in a manager chain, the group "Engineering" and the group "Platform Team". The members of "Platform Team" are
a subset of the members of "Engineering". To start again, restart the container.

1. Open http://127.0.0.1:8000. Each change in the UI saves to the directory first. Then the IdP provisions the change
   immediately and shows the result. A provisioning failure does not remove the local change.

   A delete is different: the IdP deletes a provisioned user or group at the SP first. It deletes the local copy only
   when the SP confirms. After a failure, the user or group and its SCIM id stay, and the next "Delete" sends the
   request again.

2. Click "Provision everything" for a full cycle: every user, then every group. Or run:

   ```
   docker compose --file demo/compose.yaml exec identity-provider bin/console idp:provision
   ```

   The command prints each request line and its status.

3. Open "Provisioning log". It shows each request and response. The log does not show the token.

4. Open "Import" to read the users of the SP page by page. See [Import](#import).

## Deploy

`docker build demo/identity-provider` makes an image that serves the IdP with `php -S` on port 8000 in the
environment `prod`. Set `APP_SECRET`, because the value in `.env` is public, and the variables below.

Without Docker, `composer install` in `demo/identity-provider/` installs the IdP. Its `bin/console` and
`public/index.php` use only that `vendor/`.

## Configure the service provider

The environment variables give the start values:

| Variable | Default | Meaning |
| --- | --- | --- |
| `SCIM_BASE_URL` | `http://127.0.0.1:8001/scim/v2` | The SCIM base URL of the SP. The IdP appends `/Users` and `/Groups`. `demo/compose.yaml` sets `http://service-provider:8001/scim/v2`. |
| `SCIM_TOKEN` | `demo-token` | The token that the IdP sends. |
| `SCIM_DIALECT` | `entra` | `entra`, `entra-flag`, `okta` or `keycloak`. |

Example: `SCIM_DIALECT=okta docker compose --file demo/compose.yaml up --build`.

The "Settings" page changes the same values. After you save the page, the stored values replace the environment
variables.

## What each dialect sends

All dialects send `Accept: application/scim+json`. A request with a body has `Content-Type: application/scim+json`.

| Event | `entra` | `entra-flag` (Entra with `aadOptscim062020`) | `okta` | `keycloak` (scim-outbound-for-keycloak with `syncGroups`) |
| --- | --- | --- | --- | --- |
| Authentication | `Authorization: Bearer <token>` | as `entra` | `Authorization: <token>` without `Bearer`, `User-Agent: Okta SCIM Client 1.0.0` | as `entra` |
| Discovery | None | as `entra` | as `entra` | `GET /ServiceProviderConfig` once per HTTP request or command, before the first change. `patch.supported` `false` selects PUT for every change below. A failed request assumes PATCH. |
| New user | `GET /Users?filter=userName eq "..."`, then `filter=externalId eq "..."`, then `POST /Users` | as `entra` | `GET /Users?filter=userName eq "..."&startIndex=1&count=100`, then `POST /Users` with `password`, `groups: []` and `locale` | `GET /Users?filter=userName eq "..."`, then `POST /Users` with the Keycloak id as `externalId`. No password, phone number or manager. |
| A filter finds the user | The IdP adopts the user and PATCHes the differences | as `entra` | The IdP adopts the user and sends a PUT | The IdP adopts the user only when its `externalId` is the Keycloak id, then sends the PATCH below. Else the provisioning fails. |
| Changed user | `GET /Users/{id}`, then one PATCH with one operation per changed attribute: `Add` for a new value, `Replace` for a changed value | `GET`, then lowercase `add` and `replace`. Two or more changed plain attributes go into one `replace` without `path`, with keys such as `name.givenName`. One changed attribute is a `replace` with `path`. | `GET /Users/{id}`, then `PUT` of the GET response with the changed attributes. The PUT keeps `id`, `meta` and `groups`. | No GET. One PATCH with a `replace` with `path` for each mapped attribute, changed or not: `userName`, `externalId`, `active`, `displayName`, `name` (whole object), `emails` (whole array) and `urn:ietf:params:scim:schemas:extension:enterprise:2.0:User` with an object value. Without PATCH: `PUT` of the full user with `id`. |
| Disable, enable | `GET`, then `Replace` `active` with the string `"False"` or `"True"` | `GET`, then `replace` `active` with `false` or `true` | `GET`, then `PATCH` `{"op": "replace", "value": {"active": false}}` without `path` | Part of the changed user PATCH |
| Manager | A separate PATCH after the attributes. `Add` or `Replace` with the manager id as a bare string. `Remove` clears it. | as `entra`, lowercase | Part of the POST and PUT: `{"value": "<id>"}` | Not sent |
| Cleared attribute | Not sent. Entra sends no null values. | as `entra` | The PUT leaves it out | Not sent. The SP keeps the value. |
| Delete user | `DELETE /Users/{id}` | as `entra` | Deactivation as above. Okta never deletes a user. | `PATCH` `{"op": "replace", "path": "active", "value": false}`. Without PATCH: `GET`, then `PUT` of the user with `active` `false`. This is the plugin default. |
| New group | `GET /Groups?excludedAttributes=members&filter=displayName eq "..."`, then `POST /Groups` without members, then `PATCH` `Add` `members` | as `entra`, lowercase | `GET /Groups?filter=...&startIndex=1&count=100`, then `POST /Groups` with `members: []`, then the PATCHes below | `GET /Groups?filter=displayName eq "..."`, then `POST /Groups` with the Keycloak id as `externalId` and the first 100 members, then `add` of the other members as below |
| Changed group | `GET /Groups/{id}?excludedAttributes=members`, `Replace` `displayName` when it changed, then `Add` and `Remove` of `members` with a value array | as `entra`, but each removal is `remove` with `path` `members[value eq "<id>"]` | A rename `PATCH` with `id` and `displayName` in the value on each push, then `remove` `members[value eq "<id>"]` and `add` `members` with `value` and `display` | `replace` `displayName` on each sync, then one PATCH per 100 additions, `add` with `path` `members` and a value array, then one per 100 removals, `remove` with `path` `members` and a value array. Without PATCH: one `PUT` with every member. |
| Delete group | `DELETE /Groups/{id}` | as `entra` | as `entra` | as `entra` |

A linked user that the SP no longer has (404 on GET) goes through the filter queries and the POST again. The same
applies to an Entra group. Okta does not read a linked group. Keycloak reads no linked user or group: a 404 answer to
its PATCH or PUT starts the filter query and the POST again. A 404 answer to a delete or to the Okta or Keycloak
deactivation counts as success.

A Keycloak id is a UUID. The IdP makes it from the directory id, for example `00000000-0000-4000-8000-000000000007`.

The IdP does not adopt a resource that a filter finds when another IdP user or group is already linked to it.
The provisioning of the second user or group fails, and the error names both. The user name is unique regardless of
case, as the Entra UPN and the Okta login are.

The manager of a user goes to the SP only after the manager has a SCIM id. A full cycle provisions the users in the
order of their creation.

## Import

"Import" reads every user of the SP and shows the users with each request. The directory does not change. The
command does the same:

```
docker compose --file demo/compose.yaml exec identity-provider bin/console idp:import --count=2
```

| Dialect | Requests |
| --- | --- |
| `keycloak` | `GET /Users?cursor&count=<count>`, then `GET /Users?cursor=<nextCursor>&count=<count>` until a page has no `nextCursor` (RFC 9865). A `nextCursor` that the IdP already sent stops the import with an error. When the first page has no `nextCursor` and `totalResults` is larger than the page, the IdP continues with `startIndex`, as the plugin does. |
| Others | `GET /Users?startIndex=1&count=<count>`, then the next `startIndex` until `totalResults` |

The default page size is 100, as Keycloak and Okta use. The 6 users of `idp:seed` fit on one page. Set the page size
to 2 to see three cursor pages.

## Tests

```
vendor/bin/phpunit tests/demo/IdentityProvider
```

In the test environment, a `MockHttpClient` replaces the HTTP client. Its response factory sends each request to a
library `ScimServer` with in-memory repositories. Each test run uses its own SQLite file in the kernel cache
directory.

## Code

The code is in the github.com/tacticmedia/scim monorepo, directory `demo/identity-provider/`. The tests are in
`tests/demo/IdentityProvider/`.
