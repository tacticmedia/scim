# Microsoft Entra ID setup

These steps connect a Microsoft Entra ID tenant to an application that uses the bundle. Entra then creates, changes
and disables the users and groups of the application. [Identity providers](../bundle/identity-providers.md#microsoft-entra-id)
tells what Entra sends and what to map.

!!! note
    The steps come from the Microsoft documentation, read on 2026-11-07. They were not done in a tenant. The admin
    center changes often, so a label can be different.

## Before you start

- The SCIM base URL of the application, for example `https://example.com/scim/v2`. Entra must reach it over HTTPS
  from the internet.
- A token of `access_tokens` ([Configuration](../bundle/index.md#configuration)).
- The mapping of the classes ([Mapping](../bundle/index.md#mapping)). An attribute that Entra sends but the classes
  do not map fails each PATCH request that contains it.
- An Entra account with at least the **Application Administrator** role.
- Microsoft Entra ID Free provisions users. Group assignment, group provisioning and the provisioning logs need
  Microsoft Entra ID P1 or P2.

## 1. Create the application

1. Sign in to the [Microsoft Entra admin center](https://entra.microsoft.com).
2. Go to **Entra ID** > **Enterprise apps**.
3. Select **+ New application** > **+ Create your own application**.
4. Enter a name, select *Integrate any other application you don't find in the gallery (Non-gallery)*, and create
   the application. Entra opens it.

## 2. Connect the application

1. In the application, select **Provisioning**.
2. Select **+ New configuration**.
3. Select the authentication with a bearer token, and set these fields:

    | Field | Value |
    | --- | --- |
    | **Tenant URL** | The base URL, for example `https://example.com/scim/v2`. |
    | **Secret Token** | The token. Entra sends `Authorization: Bearer <token>`. |

4. Select **Test Connection**. Entra queries a user by a random GUID. The bundle answers 200 with an empty list.
5. Select **Create**. Entra creates the provisioning job.

- Do not leave **Secret Token** empty. Entra then sends a token that Entra issues, and the static token handler does
  not accept it.
- The new configuration also offers OAuth 2.0 client credentials and Workload Identity Federation. The static token
  handler supports neither. For these methods, use another token handler ([Firewall](../bundle/index.md#firewall)).
- `?aadOptscim062020` at the end of the tenant URL changes the PATCH format. The bundle accepts both formats
  ([Connection](../bundle/identity-providers.md#connection)).

### Legacy provisioning page

Some tenants show the older provisioning page. On that page:

1. Set **Mode** to **Automatic**.
2. Expand **Admin Credentials**, set **Tenant URL** and **Secret Token**, and select **Test Connection**. Save.
3. **Mappings** has the attribute mappings, and **Settings** has the notification email and the scope.
4. **Provisioning Status** **On** starts provisioning. **Off** pauses it.

## 3. Map the attributes

1. Select **Attribute mapping**. There is one mapping for users and one for groups.
2. Open the user mapping. Compare each row with the
   [user attributes](../bundle/identity-providers.md#user-attributes) of Entra. For a row that the classes do not
   map, select the trash can icon of the row. Entra does not let you delete a required attribute. Select **Save**.
3. Do the same for the group mapping. To provision no groups, disable the group mapping.

The default user mapping contains the enterprise extension attributes `department`, `employeeNumber` and `manager`.
For an attribute of an [application extension](../bundle/index.md#application-extensions):

1. In **Attribute mapping**, select **Users**, then **Advanced Options** > **Edit target User attributes**.
2. At the bottom of the list, enter the attribute and select **Add Attribute**. The name must have the pattern
   `urn:ietf:params:scim:schemas:extension:<Extension>:2.0:User:<attribute>`, so give the extension of the class a
   URN with this pattern.
3. Add a mapping to the new attribute.

If **Edit target User attributes** is not shown, open
`https://portal.azure.com/?Microsoft_AAD_Connect_Provisioning_forceSchemaEditorEnabled=true` and go to the
application again. For a non-gallery application, Entra does not support custom attributes that are references,
multi-valued or complex.

## 4. Assign users and groups

1. Select **Users and groups**.
2. Assign the users and groups to provision.

- With the scope **Sync only assigned users and groups**, which Microsoft recommends, Entra provisions only assigned
  users and groups.
- Entra provisions only the direct members of an assigned group, not the members of nested groups.
- Entra provisions a user only while the user is enabled in Entra.

## 5. Test with one user

1. Select **Provision on demand**.
2. Search for an assigned user, and select **Provision**.

Entra shows the steps **Import user**, **Determine if user is in scope**, **Match user between source and target** and
**Perform action**. **View details** shows the attributes of each step. A failed connection shows as a
**Test connection** step.

- Provision on demand takes one user at a time, or a group with at most five members.
- `?aadOptscim062020` does not apply to provision on demand.

## 6. Set the properties

1. Select **Overview** > **Properties**, then the pencil.
2. Enable notification emails, and enter the address that gets the quarantine emails.
3. Enable the prevention of accidental deletions.
4. Select **Apply**.

## 7. Start provisioning

1. Select **Start provisioning**.
2. Select **Provisioning logs** to see each action of the provisioning service.

The first cycle takes longer than the later cycles, which run about every 40 minutes. A change of the attribute
mappings starts a new first cycle. [Failures](../bundle/identity-providers.md#failures) tells how Entra retries and
when it puts the job in quarantine. After you correct a failure, select **Restart provisioning**.

## Sources

Microsoft Learn, read on 2026-11-07:

- [Develop a SCIM endpoint and integrate it](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/use-scim-to-provision-users-and-groups)
- [Customize attribute mappings](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/customize-application-attributes)
- [Provision on demand](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/provision-on-demand)
- [User provisioning management](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/configure-automatic-user-provisioning-portal)
- [How provisioning works](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/how-provisioning-works)
- [Quarantine status](https://learn.microsoft.com/en-us/entra/identity/app-provisioning/application-provisioning-quarantine-status)
- [Licensing](https://learn.microsoft.com/en-us/entra/fundamentals/licensing)
