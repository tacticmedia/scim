<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\SchemaUrn;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Converter\NativeConverter;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmObjectStore;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Object\DocumentMapper;
use TacticMedia\ScimServerBundle\Tests\Fixtures\ArrayObjectStore;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Enum\UserType;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\Account;

final class DocumentMapperWriteTest extends KernelTestCase
{
    use CreatesDocumentMapper;

    #[Test]
    #[TestDox('A create writes fields, converts values, assigns slot elements and resolves the manager')]
    public function createWritesTheDocument(): void
    {
        $mappings = self::kernelMappings();
        $manager = new User('boss');
        $user = new User('placeholder');
        $lastModified = $user->getUpdatedAt();

        self::mapper($mappings, new ArrayObjectStore()->add($mappings->get('User'), $manager))->write($mappings->get('User'), [
            'id' => 'ignored',
            'meta' => ['lastModified' => '2020-01-01T00:00:00Z'],
            'userName' => 'bjensen',
            'name' => ['givenName' => 'Barbara'],
            'displayName' => '',
            'userType' => 'employee',
            'emails' => [['value' => 'home@example.com', 'type' => 'home'], ['value' => 'bjensen@example.com']],
            'phoneNumbers' => [['value' => '555-1', 'type' => 'Work'], ['value' => '555-2', 'type' => 'work', 'primary' => true], ['value' => '555-3', 'type' => 'fax']],
            SchemaUrn::EnterpriseUser->value => ['employeeNumber' => '701984', 'manager' => ['value' => (string) $manager->getId()]],
        ], $user, true);

        self::assertSame(
            ['bjensen', 'Barbara', null, null, UserType::Employee, true, 'bjensen@example.com', '555-2', null, '701984', null, $manager, $lastModified],
            [$user->getUserName(), $user->getFirstName(), $user->getLastName(), $user->getDisplayName(), $user->getType(), $user->isEnabled(), $user->getEmail(), $user->getPhone(), $user->getMobile(), $user->getEmployeeNumber(), $user->getDepartment(), $user->getManager(), $user->getUpdatedAt()],
        );
    }

    #[Test]
    #[TestDox('A replace writes null or the default for absent attributes')]
    public function replaceClearsAbsentAttributes(): void
    {
        $mappings = self::kernelMappings();
        $user = new User('bjensen');
        $user->setFirstName('Barbara');
        $user->setEnabled(false);
        $user->setEmail('bjensen@example.com');
        $user->setManager(new User('boss'));

        self::mapper($mappings)->write($mappings->get('User'), ['userName' => 'bjensen'], $user, false);

        self::assertSame([null, true, null, null], [$user->getFirstName(), $user->isEnabled(), $user->getEmail(), $user->getManager()]);
    }

    #[Test]
    #[TestDox('An unchanged manager id needs no store lookup')]
    public function unchangedManagerIsNotLookedUp(): void
    {
        $mappings = self::kernelMappings();
        $store = new ArrayObjectStore();
        $manager = new User('boss');
        $user = new User('bjensen');
        $user->setManager($manager);

        self::mapper($mappings, $store)->write($mappings->get('User'), ['userName' => 'bjensen', SchemaUrn::EnterpriseUser->value => ['manager' => ['value' => (string) $manager->getId()]]], $user, false);

        self::assertSame([$manager, []], [$user->getManager(), $store->lookups]);
    }

    /**
     * @param array<string, mixed> $document
     */
    #[Test]
    #[TestDox('An unknown reference id or a value that does not convert is 400 invalidValue')]
    #[DataProvider('invalidDocuments')]
    public function invalidValueFails(array $document, string $detail): void
    {
        $mappings = self::kernelMappings();

        try {
            self::mapper($mappings)->write($mappings->get('User'), $document, new User('bjensen'), true);
            self::fail('No exception.');
        } catch (ScimException $scimException) {
            self::assertSame([400, 'invalidValue', $detail], [$scimException->status, $scimException->scimType?->value, $scimException->detail]);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidDocuments(): iterable
    {
        yield 'unknown manager' => [['userName' => 'a', SchemaUrn::EnterpriseUser->value => ['manager' => ['value' => 'nope']]], 'Attribute "manager": "nope" is not the id of a User.'];
        yield 'unknown enum value' => [['userName' => 'a', 'userType' => 'Temp'], 'Attribute "userType": must be one of Employee, Contractor, Intern.'];
    }

    #[Test]
    #[TestDox('The password is hashed for the object; an absent password keeps the hash')]
    public function passwordIsHashed(): void
    {
        $mappings = self::mappings(['User' => Account::class]);
        $mapper = self::mapper($mappings);
        $account = new Account('jdoe');

        $mapper->write($mappings->get('User'), ['userName' => 'jdoe', 'password' => 'secret'], $account, true);
        $hash = (string) $account->password;
        $mapper->write($mappings->get('User'), ['userName' => 'jdoe'], $account, false);

        self::assertTrue(password_verify('secret', $hash));
        self::assertSame($hash, $account->password);
    }

    #[Test]
    #[TestDox('An immutable attribute is written on create, and on replace while it has no value')]
    public function immutableIsWrittenWhileUnassigned(): void
    {
        $mappings = self::mappings(['User' => Account::class]);
        $mapper = self::mapper($mappings);
        $created = new Account('jdoe');
        $replaced = new Account('asmith');

        $mapper->write($mappings->get('User'), ['userName' => 'jdoe', Account::EXTENSION => ['badge' => 'B1']], $created, true);
        $mapper->write($mappings->get('User'), ['userName' => 'asmith', Account::EXTENSION => ['badge' => 'B3']], $replaced, false);
        $mapper->write($mappings->get('User'), ['userName' => 'jdoe', Account::EXTENSION => ['badge' => 'B2']], $created, false);

        self::assertSame(['B1', 'B3'], [$created->badge, $replaced->badge]);
    }

    /**
     * @param array<string, mixed>   $document
     * @param array{?string, string} $expected street and postal code
     */
    #[Test]
    #[TestDox('Each member of a slot group gets its sub-attribute, else its default')]
    #[DataProvider('addresses')]
    public function slotGroupMembersAreWritten(array $document, array $expected): void
    {
        $mappings = self::mappings(['User' => Account::class]);
        $account = new Account('jdoe');

        self::mapper($mappings)->write($mappings->get('User'), ['userName' => 'jdoe'] + $document, $account, false);

        self::assertSame($expected, [$account->street, $account->postalCode]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array{?string, string}}>
     */
    public static function addresses(): iterable
    {
        yield 'both sub-attributes' => [['addresses' => [['streetAddress' => 'Main 1', 'postalCode' => '1234', 'type' => 'work']]], ['Main 1', '1234']];
        yield 'one sub-attribute' => [['addresses' => [['streetAddress' => 'Main 1', 'type' => 'work']]], ['Main 1', '0000']];
        yield 'no element of the group' => [['addresses' => [['streetAddress' => 'Home 1', 'type' => 'home']]], [null, '0000']];
        yield 'no attribute' => [[], [null, '0000']];
    }

    #[Test]
    #[TestDox('A dateTime becomes an instance of the member class in UTC')]
    public function dateTimeBecomesTheMemberClass(): void
    {
        $mappings = self::mappings(['User' => Account::class]);
        $account = new Account('jdoe');

        self::mapper($mappings)->write($mappings->get('User'), ['userName' => 'jdoe', Account::EXTENSION => ['hiredAt' => '2026-11-05T12:00:00+02:00']], $account, false);

        self::assertInstanceOf(\DateTime::class, $account->hiredAt);
        self::assertSame('2026-11-05T10:00:00+00:00', $account->hiredAt->format(\DATE_ATOM));
    }

    #[Test]
    #[TestDox('valueOf() resolves a reference and gives null for a collection')]
    public function valueOfResolvesReferences(): void
    {
        $mappings = self::kernelMappings();
        $manager = new User('boss');
        $mapper = self::mapper($mappings, new ArrayObjectStore()->add($mappings->get('User'), $manager));
        $user = $mappings->get('User');
        $document = [SchemaUrn::EnterpriseUser->value => ['manager' => ['value' => (string) $manager->getId()]]];

        $member = static fn (string $name): AttributeMapping => array_find($user->attributes, static fn (AttributeMapping $attribute): bool => $name === $attribute->member) ?? throw new \LogicException($name);

        self::assertSame($manager, $mapper->valueOf($user, $member('manager'), $document));
        self::assertNull($mapper->valueOf($user, $member('groups'), $document));
        self::assertNull($mapper->valueOf($user, $member('firstName'), $document));
    }

    #[Test]
    #[TestDox('A converter or store service that does not implement its interface fails')]
    public function wrongServiceFails(): void
    {
        $mappings = self::kernelMappings();
        $wrong = static fn (): \stdClass => new \stdClass();
        $converters = new ServiceLocator([NativeConverter::class => $wrong, IdConverter::class => static fn (): IdConverter => new IdConverter()]);
        $stores = new ServiceLocator([OrmObjectStore::class => $wrong]);
        $document = ['userName' => 'a', SchemaUrn::EnterpriseUser->value => ['manager' => ['value' => 'x']]];
        $failures = [];

        foreach ([[$converters, new ServiceLocator([])], [self::converters(), $stores]] as [$converterLocator, $storeLocator]) {
            try {
                new DocumentMapper($mappings, $converterLocator, $storeLocator)->write($mappings->get('User'), $document, new User('a'), true);
            } catch (\LogicException $exception) {
                $failures[] = $exception->getMessage();
            }
        }

        self::assertSame([
            'The converter service "TacticMedia\ScimServerBundle\Converter\NativeConverter" does not implement TacticMedia\ScimServerBundle\Converter\ValueConverter.',
            \sprintf('The store service of %s does not implement TacticMedia\ScimServerBundle\Object\ObjectStore.', User::class),
        ], $failures);
    }
}
