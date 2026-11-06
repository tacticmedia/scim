<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Provisioning;

use Doctrine\ORM\EntityManagerInterface;
use TacticMedia\ScimDemo\IdentityProvider\Repository\ProvisioningSettingsRepository;

/**
 * Reads every user of the service provider page by page. Keycloak asks for an empty cursor first (RFC 9865), follows
 * nextCursor, and continues by startIndex when the first page has none. The other dialects page by startIndex. Each
 * call flushes the log entries, also when it fails.
 *
 * @see https://github.com/shebang-labs/scim-outbound-for-keycloak/blob/main/src/main/java/io/shebanglabs/scimoutbound/http/UserListing.java
 */
final readonly class UserImporter
{
    public const int PAGE_SIZE = 100;

    public function __construct(
        private ScimClient $scim,
        private ProvisioningSettingsRepository $settingsRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param int<1, max> $count
     *
     * @return list<array<mixed>>
     *
     * @throws ProvisioningFailed when a page request fails
     */
    public function import(int $count = self::PAGE_SIZE): array
    {
        try {
            return Dialect::Keycloak === $this->settingsRepository->current()->dialect ? $this->byCursor($count) : $this->byIndex($count, 1, []);
        } finally {
            $this->entityManager->flush();
        }
    }

    /**
     * @return list<array<mixed>>
     */
    private function byCursor(int $count): array
    {
        $page = $this->scim->send('GET', '/Users?cursor&count='.$count);
        $users = $this->resources($page);
        $cursor = $this->nextCursor($page);

        if (null === $cursor) {
            return [] !== $users && \count($users) < $this->totalResults($page) ? $this->byIndex($count, 1 + \count($users), $users) : $users;
        }

        while (null !== $cursor) {
            $page = $this->scim->send('GET', '/Users', ['cursor' => $cursor, 'count' => $count]);
            $users = [...$users, ...$this->resources($page)];
            $cursor = $this->nextCursor($page);
        }

        return $users;
    }

    /**
     * @param list<array<mixed>> $users
     *
     * @return list<array<mixed>>
     */
    private function byIndex(int $count, int $startIndex, array $users): array
    {
        do {
            $page = $this->scim->send('GET', '/Users', ['startIndex' => $startIndex, 'count' => $count]);
            $resources = $this->resources($page);
            $users = [...$users, ...$resources];
            $startIndex += \count($resources);
        } while ([] !== $resources && $startIndex <= $this->totalResults($page));

        return $users;
    }

    /**
     * @param array<mixed> $page
     *
     * @return list<array<mixed>>
     */
    private function resources(array $page): array
    {
        return array_values(array_filter(Json::object($page, 'Resources'), \is_array(...)));
    }

    /**
     * @param array<mixed> $page
     */
    private function nextCursor(array $page): ?string
    {
        $cursor = Json::string($page, 'nextCursor');

        return '' === $cursor ? null : $cursor;
    }

    /**
     * @param array<mixed> $page
     */
    private function totalResults(array $page): int
    {
        $totalResults = $page['totalResults'] ?? 0;

        return \is_int($totalResults) ? $totalResults : 0;
    }
}
