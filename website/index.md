# tacticmedia/scim

A SCIM 2.0 server (RFC 7643, RFC 7644) for PHP and Symfony. Microsoft Entra ID, Okta and other identity providers
use it to create, change and deactivate the users and groups of your application.

| Package | Use |
| --- | --- |
| [`tacticmedia/scim-server-bundle`](https://packagist.org/packages/tacticmedia/scim-server-bundle) | The Symfony bundle. Map Doctrine entities or other classes with attributes, and the bundle answers every SCIM endpoint. |
| [`tacticmedia/scim-server`](https://packagist.org/packages/tacticmedia/scim-server) | The library. It needs no framework. You supply one repository for each resource type, and you authenticate the requests. |

Requirements: PHP 8.5 or later. The bundle needs Symfony 8.1 or a later 8.x version.

```bash
composer require tacticmedia/scim-server-bundle symfony/security-bundle
```

## Pages

- [Bundle](bundle/index.md): installation, configuration, mapping and the reference.
- [Identity providers](bundle/identity-providers.md): what each identity provider sends, and what to map.
- [Microsoft Entra ID setup](identity-providers/entra-id.md) and [Okta setup](identity-providers/okta.md): the steps
  in the admin console of the identity provider.
- [Library](library.md): the library without the bundle.

## Coding agents

The bundle ships an [Agent Skill](https://agentskills.io/specification) that integrates it into an application.
With [Symfony AI Mate](https://symfony.com/doc/current/ai/components/mate.html), `mate discover` installs it. Without
Mate, tell the agent: "Integrate tacticmedia/scim-server-bundle. Follow
`vendor/tacticmedia/scim-server-bundle/skills/scim-integration/SKILL.md`."

## Source

The code is in the [tacticmedia/scim](https://github.com/tacticmedia/scim) monorepo. Send issues and pull requests
there.
