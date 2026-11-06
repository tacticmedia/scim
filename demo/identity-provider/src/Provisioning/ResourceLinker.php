<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

use Doctrine\ORM\EntityManagerInterface;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryGroup;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;

/**
 * Links a directory entry to a service provider resource that no other entry holds. Two entries on one resource
 * would overwrite it in turn.
 */
final readonly class ResourceLinker
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<mixed> $resource
     *
     * @throws ProvisioningFailed when another entry holds the resource
     */
    public function link(DirectoryUser|DirectoryGroup $entry, array $resource): void
    {
        $id = ScimClient::id($resource);
        $holder = $this->entityManager->getRepository($entry::class)->findOneBy(['scimId' => $id]);

        if (null !== $holder && $holder !== $entry) {
            throw ProvisioningFailed::alreadyLinked($id, $this->name($entry), $this->name($holder));
        }

        $entry->scimId = $id;
    }

    /**
     * Keycloak without linkExistingAccounts: only a resource that carries its own id as externalId.
     *
     * @param array<mixed> $resource
     *
     * @throws ProvisioningFailed when the externalId differs or another entry holds the resource
     */
    public function linkProvisioned(DirectoryUser|DirectoryGroup $entry, array $resource, string $externalId): void
    {
        if ($externalId !== Json::string($resource, 'externalId')) {
            throw ProvisioningFailed::provisionedElsewhere($this->name($entry), $externalId);
        }

        $this->link($entry, $resource);
    }

    private function name(DirectoryUser|DirectoryGroup $entry): string
    {
        return $entry instanceof DirectoryUser ? $entry->userName : $entry->displayName;
    }
}
