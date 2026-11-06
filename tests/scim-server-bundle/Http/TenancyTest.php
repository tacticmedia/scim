<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServer\MessageUrn;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\HiddenUserFilter;

/**
 * A Doctrine SQL filter that the application enables at runtime, as a tenant filter.
 */
final class TenancyTest extends WebTestCase
{
    use ScimClient;

    #[Test]
    #[TestDox('After a failed Bulk operation, the next operation still runs with the runtime SQL filter')]
    public function bulkKeepsTheRuntimeFilter(): void
    {
        $otherTenant = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'bjensen']));
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->getConfiguration()->addFilter('hidden_user', HiddenUserFilter::class);
        $entityManager->getFilters()->enable('hidden_user')->setParameter('userName', 'bjensen');

        $response = $this->send('POST', '/scim/v2/Bulk', ['schemas' => [MessageUrn::BulkRequest->value], 'Operations' => [
            ['method' => 'POST', 'path' => '/Groups', 'bulkId' => 'staff', 'data' => ['schemas' => [Scim::GROUP], 'displayName' => 'Staff', 'members' => [['value' => (string) Uuid::v7()]]]],
            ['method' => 'PUT', 'path' => '/Users/'.$otherTenant, 'data' => ['schemas' => [Scim::USER], 'userName' => 'taken-over']],
        ]]);
        $operations = self::json($response)['Operations'] ?? null;
        self::assertIsArray($operations);

        self::assertSame(['400', '404'], array_column($operations, 'status'));
        $entityManager->getFilters()->disable('hidden_user');
        self::assertSame('bjensen', $entityManager->getConnection()->fetchOne('SELECT userName FROM app_user'));
    }
}
