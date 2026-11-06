<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServer\Server\RequestedProjection;
use TacticMedia\ScimServer\Server\ResourcePresenter;

final class ResourcePresenterTest extends KernelTestCase
{
    private const string GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    #[Test]
    #[TestDox('present() fills a "$ref" to another registered resource type before it applies the projection')]
    public function fillsReferenceToRegisteredType(): void
    {
        $users = new RegisteredResourceType(CoreResourceTypes::user(), new InMemoryResourceRepository(CoreResourceTypes::user(), new MockClock()));
        $memberRef = new AttributePath(null, 'members', '$ref');

        $resource = new ResourcePresenter([$users])->present(
            ['schemas' => [self::GROUP], 'id' => 'g1', 'displayName' => 'Tour Guides', 'members' => [['value' => 'u1', 'type' => 'User']]],
            CoreResourceTypes::group(),
            'https://example.com/scim/v2',
            new RequestedProjection([$memberRef]),
        );

        self::assertSame(['schemas' => [self::GROUP], 'id' => 'g1', 'members' => [['$ref' => 'https://example.com/scim/v2/Users/u1']]], $resource);
    }

    #[Test]
    #[TestDox('present() of the withServerValues() result equals present() of the stored document, also its computed ETag')]
    public function presentsServerValuesUnchanged(): void
    {
        $presenter = new ResourcePresenter();
        $group = CoreResourceTypes::group();
        $stored = ['schemas' => [self::GROUP], 'id' => 'g1', 'meta' => ['created' => '2026-11-01T08:00:00Z'], 'members' => [['value' => 'g2', 'type' => 'Group']]];

        $withServerValues = $presenter->withServerValues($stored, $group, 'https://example.com/scim/v2');

        self::assertSame('Group', $withServerValues['meta']['resourceType'] ?? null);
        self::assertSame($presenter->present($stored, $group, 'https://example.com/scim/v2', new RequestedProjection()), $presenter->present($withServerValues, $group, 'https://example.com/scim/v2', new RequestedProjection()));
    }
}
