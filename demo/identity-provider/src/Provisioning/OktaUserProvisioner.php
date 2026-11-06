<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;

/**
 * Okta looks a new user up by userName, then creates it. It changes the activation with a path-less PATCH and the
 * profile with a PUT built from the GET response. It never deletes a user.
 *
 * @see https://developer.okta.com/docs/api/openapi/okta-scim/guides/scim-20/
 */
final readonly class OktaUserProvisioner
{
    public const array PAGE = ['startIndex' => 1, 'count' => 100];

    public function __construct(
        private ScimClient $scim,
        private ResourceLinker $linker,
    ) {
    }

    public function sync(DirectoryUser $user): void
    {
        $current = $this->scim->get('/Users', $user->scimId) ?? $this->matched($user);

        if (null === $current) {
            $user->scimId = ScimClient::id($this->scim->send('POST', '/Users', body: OktaUserMapping::createBody($user)));

            return;
        }

        $current = $this->activation($user, $current);
        $profile = OktaUserMapping::profile($current, $user);

        if ($profile !== $current) {
            $this->scim->send('PUT', $this->path($user), body: $profile);
        }
    }

    public function deactivate(string $scimId): void
    {
        $this->scim->deprovision('PATCH', '/Users/'.rawurlencode($scimId), $this->activeOperation(false));
    }

    /**
     * @return array<mixed>|null
     */
    private function matched(DirectoryUser $user): ?array
    {
        $match = $this->scim->first('/Users', ['filter' => Scim::equals('userName', $user->userName), ...self::PAGE]);

        if (null !== $match) {
            $this->linker->link($user, $match);
        }

        return $match;
    }

    /**
     * @param array<mixed> $current
     *
     * @return array<mixed> the resource after the change
     */
    private function activation(DirectoryUser $user, array $current): array
    {
        if (($current['active'] ?? null) === $user->active) {
            return $current;
        }

        $this->scim->send('PATCH', $this->path($user), body: $this->activeOperation($user->active));

        return [...$current, 'active' => $user->active];
    }

    /**
     * @return array<string, mixed>
     */
    private function activeOperation(bool $active): array
    {
        return Scim::patch([['op' => 'replace', 'value' => ['active' => $active]]]);
    }

    private function path(DirectoryUser $user): string
    {
        return '/Users/'.rawurlencode((string) $user->scimId);
    }
}
