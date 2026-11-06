<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Schema\Uniqueness;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServer\Server\ScimServer;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\NamesakeGroup;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\PrefixedGroup;

/**
 * Group displayName is unique by default; a mapping can relax it.
 */
final class GroupUniquenessTest extends KernelTestCase
{
    #[Test]
    #[TestDox('A displayName mapping without uniqueness: publishes uniqueness server')]
    public function publishesServerByDefault(): void
    {
        self::assertSame(Uniqueness::Server, new AttributeLoader()->load(PrefixedGroup::class)->resourceType->schema->attribute('displayName')?->uniqueness);
    }

    #[Test]
    #[TestDox('uniqueness: none on displayName lets two groups have the same name')]
    public function acceptsDuplicateNamesWhenRelaxed(): void
    {
        $type = new AttributeLoader()->load(NamesakeGroup::class)->resourceType;
        $server = new ScimServer([new RegisteredResourceType($type, new InMemoryResourceRepository($type, new MockClock()))]);
        $create = static fn (): int => $server->handle(Request::create('https://example.com/scim/v2/Groups', 'POST', server: ['CONTENT_TYPE' => 'application/scim+json'], content: '{"displayName":"Tour Guides"}'), '/scim/v2')->getStatusCode();

        self::assertSame(Uniqueness::None, $type->schema->attribute('displayName')?->uniqueness);
        self::assertSame([201, 201], [$create(), $create()]);
    }
}
