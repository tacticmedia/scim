# Okta setup

These steps connect an Okta org to an application that uses the bundle. Okta then creates, changes and deactivates
the users of the application, and pushes groups. [Identity providers](../bundle/identity-providers.md#okta) tells what
Okta sends and what to map.

!!! note
    The steps come from the Okta documentation, read on 2026-11-07. They were not done in an org. The Admin Console
    changes often, so a label can be different.

## Before you start

- The SCIM base URL of the application, for example `https://example.com/scim/v2`. Okta must reach it over HTTPS from
  the internet. Okta rejects a base URL with `_`.
- A token of `access_tokens` ([Configuration](../bundle/index.md#configuration)).
- The mapping of the classes ([Mapping](../bundle/index.md#mapping)). Okta requires `userName`, `name.givenName`,
  `name.familyName`, `emails` and `active`.
- An Okta admin account with the Super Administrator role, or an App Administrator who can manage the application.
- For tests, an [Okta Integrator Free Plan](https://developer.okta.com/signup/) org: at most 10 active users, and
  Okta deactivates it after 90 days without a sign-in.

## 1. Create the app integration

1. In the Admin Console, go to **Applications and Resources** > **Applications**.
2. Select **Create App Integration**, then **Classic experience**.
3. Select **SAML 2.0** or **SWA** as the sign-in method, and complete the wizard. For an internal application, select
   *I'm an Okta customer adding an internal app* and *This is an internal app that we have created*.
4. On the **General** tab, in **App Settings**, select **Edit**. In the **Provisioning** field, select **SCIM**, and
   select **Save**.

- SCIM is not available for an OIDC integration of the Classic experience.
- If the **Provisioning** field has no **SCIM** option, Okta tells you to contact Okta Support. For a test, you can
  use the catalog template instead: **Browse App Catalog**, search for `SCIM 2.0 Test App (Header Auth)`, add it,
  then on the **Provisioning** tab select **Configure API Integration** and **Enable API Integration**.

## 2. Connect the integration

1. On the **Provisioning** tab, in **Settings** > **Integration**, select **Edit**.
2. Set the fields:

    | Field | Value |
    | --- | --- |
    | SCIM connector base URL | The base URL, for example `https://example.com/scim/v2`. |
    | Unique identifier field for users | `userName`. Okta finds an existing user with `userName eq "..."`. |
    | **Supported provisioning actions** | **Push New Users**, **Push Profile Updates** and **Push Groups**. **Import New Users and Profile Updates** only if Okta must read the users of the application. |
    | **Authentication Mode** | **HTTP Header**. |
    | **Authorization** | The token. The bundle accepts it with and without `Bearer`. |

3. Save.

## 3. Set the provisioning to the application

1. On the **Provisioning** tab, select **To App**, then **Edit**.
2. Enable **Create Users**, **Update User Attributes** and **Deactivate Users**.
3. Disable **Set password when creating new users** unless the classes map `password`
   ([Password](../bundle/index.md#password)). Enable **Sync Password** only when they map it.
4. Save.

**Deactivate Users** sends `active` false when a user is unassigned or deactivated in Okta. Okta never deletes a
user.

## 4. Map the attributes

The **Attribute Mappings** section of **To App** lists what Okta sends. For a mapping that the classes do not map,
select **X** and confirm. The bundle drops an unmapped attribute of a PUT request, so this step only keeps the
requests small.

For an enterprise extension attribute, for example `department`:

1. Select **Go to Profile Editor**, then **Add Attribute**.
2. Set the external name to the attribute name and the external namespace to
   `urn:ietf:params:scim:schemas:extension:enterprise:2.0:User`. Save.
3. Back in **To App**, edit the mapping of the new attribute, and select the Okta attribute that fills it.

## 5. Assign users

1. On the **Assignments** tab, select **Assign** > **Assign to People** or **Assign to Groups**.
2. Select **Assign** in the row of each user or group, then **Save and Go Back**, then **Done**.

Okta creates each assigned user in the application. An assigned group assigns its members. To create groups in the
application, push them (step 6).

## 6. Push groups

1. On the **Push Groups** tab, select **Push Groups** > **Find groups by name** or **Find groups by rule**.
2. Select the groups. Keep **Push group memberships immediately** selected.

- Okta pushes only members that are already assigned to the application and provisioned.
- Okta does not support one group for both assignment and Group Push.
- Okta deletes a pushed group in the application only when you unlink it and select
  **Delete the group in the target app**.

## 7. Check the result

- **Dashboard** > **Tasks** shows each failed provisioning task. After you correct the cause, select the tasks and
  **Retry Selected**.
- **View Logs** on the application opens the System Log for the application.
- Okta retries a 429 response after the `Retry-After` seconds ([Rate limiting](../bundle/index.md#rate-limiting)).

## Sources

Okta documentation, read on 2026-11-07:

- [Add SCIM provisioning to app integrations](https://help.okta.com/oie/en-us/content/topics/apps/apps_app_integration_wizard_scim.htm)
- [Create SAML app integrations](https://help.okta.com/oie/en-us/content/topics/apps/apps_app_integration_wizard_saml.htm)
- [Connect a private SCIM integration](https://developer.okta.com/docs/guides/scim-provisioning-integration-connect/main/)
- [SCIM 2.0 protocol reference](https://developer.okta.com/docs/api/openapi/okta-scim/guides/scim-20/)
- [Assign an app integration to a user](https://help.okta.com/oie/en-us/content/topics/provisioning/lcm/lcm-assign-app-user.htm)
- [Push groups](https://help.okta.com/oie/en-us/content/topics/users-groups-profiles/usgp-enable-group-push.htm)
- [Provisioning troubleshooting](https://help.okta.com/oie/en-us/content/topics/provisioning/lcm/troubleshooting.htm)
- [Org defaults](https://developer.okta.com/docs/reference/org-defaults/)
