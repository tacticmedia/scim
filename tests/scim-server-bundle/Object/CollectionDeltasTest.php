<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Fan;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Gang;
use TacticMedia\ScimServerBundle\Tests\Fixtures\CreatesSchema;

/**
 * Both classes have #[ORM\Version] and an owning ManyToMany with an adder and a remover, which the ORM store supports.
 */
final class CollectionDeltasTest extends KernelTestCase
{
    use CreatesSchema;
    use DeltaServer;
    use OrmRepositories;

    #[Test]
    #[TestDox('A readOnly collection is no delta attribute')]
    public function readOnlyCollectionTakesNoSteps(): void
    {
        self::assertSame([], self::ormRepositories(self::mappings(['User' => Fan::class, 'Group' => Gang::class]))['User']->deltaAttributes());
    }

    #[Test]
    #[TestDox('A class without a meta.version mapping takes the full PATCH path')]
    public function noVersionMappingTakesFullPath(): void
    {
        $entityManager = self::createSchema();
        $entityManager->persist($alice = new Fan('alice'));
        $entityManager->persist($red = new Gang('Red'));
        $entityManager->flush();

        $mappings = self::mappings(['User' => Fan::class, 'Group' => Gang::class]);
        $add = ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => 'f'.$alice->id]]]]];

        $response = self::request(self::ormServer($mappings), 'PATCH', '/Groups/g'.$red->id, $add);

        self::assertSame([], self::ormRepositories($mappings)['Group']->deltaAttributes());
        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        $entityManager->clear();
        $stored = $entityManager->find(Gang::class, $red->id);
        self::assertInstanceOf(Gang::class, $stored);
        self::assertSame([['alice'], 2], [$stored->getFans()->map(static fn (Fan $fan): string => $fan->userName)->getValues(), $stored->version]);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel(['environment' => 'deltas'] + $options);
    }
}
