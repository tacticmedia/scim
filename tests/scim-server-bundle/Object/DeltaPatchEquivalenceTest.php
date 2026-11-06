<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use TacticMedia\ScimServer\Event\ResourcePatched;
use TacticMedia\ScimServer\Event\ResourceUpdated;
use TacticMedia\ScimServer\Repository\ResourceRepository;
use TacticMedia\ScimServer\Tests\Server\DeltaPatchEquivalenceTest as LibraryEquivalence;
use TacticMedia\ScimServerBundle\Tests\Fixtures\CreatesSchema;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;

/**
 * The member forms of the library equivalence test against the Doctrine store: the full PATCH path and the delta
 * path give the same response and the same stored group. "u1" to "u100" are users, "g1" is the group "Tour Guides"
 * with the members u1 and u2.
 */
final class DeltaPatchEquivalenceTest extends KernelTestCase
{
    use CreatesSchema;
    use DeltaServer;

    /**
     * @return iterable<string, array{list<array<string, mixed>>, bool, 2?: array<string, string>}>
     */
    public static function requests(): iterable
    {
        return LibraryEquivalence::requests();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @param array<string, string>      $headers
     */
    #[Test]
    #[DataProvider('requests')]
    #[TestDox('$_dataName gives the same result on both paths')]
    public function givesSameResult(array $operations, bool $viaDelta, array $headers = []): void
    {
        $full = $this->outcome(self::decoratedGroups(), $operations, $headers);
        $this->events = [];
        $delta = $this->outcome(self::deltaGroups(), $operations, $headers);

        self::assertSame($full, $delta);
        self::assertNotContains($viaDelta ? ResourceUpdated::class : ResourcePatched::class, $this->events);
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @param array<string, string>      $headers
     *
     * @return array{int, mixed, mixed} the status and the body of the PATCH, and the stored group without
     *                                  meta.version, with the placeholders in place of the ids. The full path writes
     *                                  "remove then add of the same member" through replace(), which increments the
     *                                  version; the delta path changes nothing.
     */
    private function outcome(ResourceRepository $groups, array $operations, array $headers): array
    {
        $clock = new MockClock('2026-11-06 10:00:00', 'UTC');
        Clock::set($clock);
        $ids = $this->seed(self::createSchema());
        $clock->sleep(60);
        $server = $this->serverOf($groups);

        $patch = self::request($server, 'PATCH', '/Groups/'.$ids['g1'], $this->translated(['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => $operations], $ids), $headers);
        $stored = json_decode((string) self::request($server, 'GET', '/Groups/'.$ids['g1'])->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($stored);
        self::assertIsArray($stored['meta']);
        unset($stored['meta']['version']);
        $placeholders = array_flip($ids);

        return [$patch->getStatusCode(), $this->translated(json_decode((string) $patch->getContent() ?: 'null', true), $placeholders), $this->translated($stored, $placeholders)];
    }

    /**
     * @return array<string, string> the id of each placeholder
     */
    private function seed(EntityManagerInterface $entityManager): array
    {
        $ids = [];
        $group = new Group('Tour Guides');

        foreach (range(1, 100) as $index) {
            $entityManager->persist($user = new User('u'.$index));
            $ids['u'.$index] = (string) $user->getId();

            if ($index <= 2) {
                $group->addUser($user);
            }
        }

        $entityManager->persist($group);
        $entityManager->flush();
        $entityManager->clear();

        return $ids + ['g1' => (string) $group->getId()];
    }

    /**
     * @param array<string, string> $replacements
     */
    private function translated(mixed $value, array $replacements): mixed
    {
        return json_decode(strtr(json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), $replacements), true, flags: \JSON_THROW_ON_ERROR);
    }
}
