<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Validation;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Patch\PatchOperationType;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Repository\ResourcePatch;
use TacticMedia\ScimServer\Repository\ValuesStep;
use TacticMedia\ScimServerBundle\Object\ObjectResourceRepository;
use TacticMedia\ScimServerBundle\Tests\Fixtures\CreatesSchema;
use TacticMedia\ScimServerBundle\Tests\Object\OrmRepositories;
use TacticMedia\ScimServerBundle\Tests\Validation\Fixtures\Member;
use TacticMedia\ScimServerBundle\Tests\Validation\Fixtures\Roster;

/**
 * Writes of Member (userName at least 3 characters, unique work email) and Roster (at most two members) through the
 * ORM store, validated in the group "scim".
 */
final class ValidatedWritesTest extends KernelTestCase
{
    use CreatesSchema;
    use OrmRepositories;
    private const string TOO_SHORT = 'Attribute "userName": This value is too short. It should have 3 characters or more.';

    private EntityManagerInterface $entityManager;

    private ObjectResourceRepository $members;

    private ObjectResourceRepository $rosters;

    #[\Override]
    protected function setUp(): void
    {
        $this->entityManager = self::createSchema();
        $repositories = self::ormRepositories(self::mappings(['User' => Member::class, 'Group' => Roster::class]), self::getContainer()->get('test.tacticmedia_scim_server.object_validator'));
        $this->members = $repositories['User'];
        $this->rosters = $repositories['Group'];
    }

    #[Test]
    #[TestDox('create() and replace() validate before the store writes, and write nothing on a violation')]
    public function createAndReplaceValidate(): void
    {
        $alice = $this->members->create(['userName' => 'alice', 'emails' => [['value' => 'alice@example.com', 'type' => 'work']]]);

        $this->assertScimError(400, self::TOO_SHORT, fn (): array => $this->members->create(['userName' => 'al']));
        $this->assertScimError(409, 'Attribute "emails[type eq "work"].value": This value is already used.', fn (): array => $this->members->create(['userName' => 'bob', 'emails' => [['value' => 'alice@example.com', 'type' => 'work']]]));
        $this->assertScimError(400, self::TOO_SHORT, fn (): array => $this->members->replace($this->id($alice), ['userName' => 'al']));

        self::assertSame(['alice'], $this->entityManager->createQuery('SELECT m.userName FROM '.Member::class.' m')->getSingleColumnResult());
    }

    #[Test]
    #[TestDox('patch() validates the members after the delta steps, and rolls back their rows on a violation')]
    public function deltaPatchValidates(): void
    {
        [$alice, $bob, $carol] = array_map(fn (string $userName): string => $this->id($this->members->create(['userName' => $userName])), ['alice', 'bob', 'carol']);
        $roster = $this->id($this->rosters->create(['displayName' => 'Red', 'members' => [['value' => $alice]]]));
        $path = new AttributePath('urn:ietf:params:scim:schemas:core:2.0:Group', 'members');
        $add = static fn (string ...$ids): ResourcePatch => new ResourcePatch(null, [new ValuesStep($path, PatchOperationType::Add, array_map(static fn (string $id): array => ['value' => $id], array_values($ids)))], null);

        self::assertNotSame([], $this->rosters->deltaAttributes());
        $this->assertScimError(400, 'Attribute "members[type eq "User"]": This collection should contain 2 elements or less.', fn (): mixed => $this->rosters->patch($roster, $add($bob, $carol)));
        $this->assertScimError(400, 'Attribute "displayName": This value is too long. It should have 20 characters or less.', fn (): mixed => $this->rosters->patch($roster, new ResourcePatch(['displayName' => str_repeat('x', 21)], [], null)));
        self::assertSame([['value' => $bob, 'type' => 'User']], $this->rosters->patch($roster, $add($bob))->changes[0]->added);

        self::assertSame(2, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM validation_roster_member'));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel(['environment' => 'validation'] + $options);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function id(array $document): string
    {
        self::assertIsString($document['id'] ?? null);

        return $document['id'];
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function assertScimError(int $status, string $detail, \Closure $call): void
    {
        try {
            $call();
            self::fail('No ScimException.');
        } catch (ScimException $scimException) {
            self::assertSame([$status, $detail, 409 === $status ? ScimErrorType::Uniqueness : ScimErrorType::InvalidValue], [$scimException->status, $scimException->detail, $scimException->scimType]);
        }
    }
}
