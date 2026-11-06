<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\KernelInterface;
use TacticMedia\ScimServer\Event\ResourcePatched;
use TacticMedia\ScimServer\Event\ResourceUpdated;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Band;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Player;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Squad;
use TacticMedia\ScimServerBundle\Tests\Fixtures\CreatesSchema;

/**
 * Band and Squad both qualify for member deltas; Band sets deltas: false.
 */
final class MemberDeltaOptOutTest extends KernelTestCase
{
    use CreatesSchema;
    use DeltaServer;
    use OrmRepositories;

    #[Test]
    #[TestDox('deltas: false empties deltaAttributes(), so a member PATCH calls the adder and dispatches ResourceUpdated')]
    public function optOutTakesFullPath(): void
    {
        $entityManager = self::createSchema();
        $entityManager->persist($alice = new Player('alice'));
        $entityManager->persist($band = new Band('Red'));
        $entityManager->persist($squad = new Squad('Blue'));
        $entityManager->flush();

        $bands = self::mappings(['User' => Player::class, 'Group' => Band::class]);
        $squads = self::mappings(['User' => Player::class, 'Group' => Squad::class]);
        $dispatcher = new EventDispatcher();

        foreach ([ResourceUpdated::class, ResourcePatched::class] as $event) {
            $dispatcher->addListener($event, function (object $event): void {
                $this->events[] = $event::class;
            });
        }

        $add = ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => 'p'.$alice->id]]]]];
        $bandStatus = self::request(self::ormServer($bands, $dispatcher), 'PATCH', '/Groups/b'.$band->id, $add)->getStatusCode();
        $squadStatus = self::request(self::ormServer($squads, $dispatcher), 'PATCH', '/Groups/s'.$squad->id, $add)->getStatusCode();

        self::assertSame([], self::ormRepositories($bands)['Group']->deltaAttributes());
        self::assertCount(1, self::ormRepositories($squads)['Group']->deltaAttributes());
        self::assertSame([204, 204, ['alice']], [$bandStatus, $squadStatus, $band->joined]);
        self::assertSame([ResourceUpdated::class, ResourcePatched::class], $this->events);
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
