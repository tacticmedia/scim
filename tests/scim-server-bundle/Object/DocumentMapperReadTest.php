<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use TacticMedia\ScimServer\SchemaUrn;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Enum\DeviceStatus;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Enum\UserType;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\Device;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\IntGroup;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\IntUser;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\Tag;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\Account;

final class DocumentMapperReadTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use CreatesDocumentMapper;
    private const string NOW = '2026-11-05T10:00:00Z';

    #[Test]
    #[TestDox('A user becomes a document in schema order with slots, manager and groups')]
    public function userBecomesADocument(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $manager = new User('boss');
        $manager->setDisplayName('The Boss');

        $group = new Group('Admins');
        $user = $this->fullUser($manager);
        $user->getGroups()->add($group);
        $mappings = self::kernelMappings();

        self::assertSame([
            'schemas' => [SchemaUrn::User->value, SchemaUrn::EnterpriseUser->value],
            'id' => (string) $user->getId(),
            'externalId' => 'ext-1',
            'meta' => ['created' => self::NOW, 'lastModified' => self::NOW, 'version' => 'W/"1"'],
            'userName' => 'bjensen',
            'name' => ['givenName' => 'Barbara', 'familyName' => 'Jensen', 'formatted' => 'Barbara Jensen'],
            'displayName' => 'Babs',
            'userType' => 'Employee',
            'active' => false,
            'emails' => [['value' => 'bjensen@example.com', 'type' => 'work', 'primary' => true]],
            'phoneNumbers' => [['value' => '555-1', 'type' => 'work', 'primary' => true], ['value' => '555-2', 'type' => 'mobile']],
            'groups' => [['value' => (string) $group->getId(), 'display' => 'Admins']],
            SchemaUrn::EnterpriseUser->value => [
                'employeeNumber' => '701984',
                'department' => 'Tour',
                'manager' => ['value' => (string) $manager->getId(), 'displayName' => 'The Boss'],
            ],
        ], self::mapper($mappings)->toDocument($mappings->get('User'), $user));
    }

    #[Test]
    #[TestDox('Null and empty values, empty complex values and an empty extension are omitted')]
    public function emptyValuesAreOmitted(): void
    {
        self::mockTime(new \DateTimeImmutable(self::NOW));
        $user = new User('jdoe');
        $user->setDisplayName('');
        $user->setMobile('555-2');

        $mappings = self::kernelMappings();

        self::assertSame([
            'schemas' => [SchemaUrn::User->value],
            'id' => (string) $user->getId(),
            'meta' => ['created' => self::NOW, 'lastModified' => self::NOW, 'version' => 'W/"1"'],
            'userName' => 'jdoe',
            'active' => true,
            'phoneNumbers' => [['value' => '555-2', 'type' => 'mobile']],
        ], self::mapper($mappings)->toDocument($mappings->get('User'), $user));
    }

    #[Test]
    #[TestDox('The display value of a reference falls back to userName')]
    public function displayFallsBackToUserName(): void
    {
        $user = new User('jdoe');
        $user->setManager(new User('boss'));

        $mappings = self::kernelMappings();

        $document = self::mapper($mappings)->toDocument($mappings->get('User'), $user);

        self::assertSame('boss', $document[SchemaUrn::EnterpriseUser->value]['manager']['displayName'] ?? null);
    }

    #[Test]
    #[TestDox('Group members of all collections are listed in declaration order with their type')]
    public function groupMembersAreListed(): void
    {
        $user = new User('jdoe');
        $subgroup = new Group('Sub');
        $group = new Group('Admins');
        $group->addSubgroup($subgroup);
        $group->addUser($user);

        $mappings = self::kernelMappings();

        $document = self::mapper($mappings)->toDocument($mappings->get('Group'), $group);

        self::assertSame([
            ['value' => (string) $user->getId(), 'display' => 'jdoe', 'type' => 'User'],
            ['value' => (string) $subgroup->getId(), 'display' => 'Sub', 'type' => 'Group'],
        ], $document['members'] ?? null);
    }

    #[Test]
    #[TestDox('An application resource type reads its prefixed int id, extension, converters and methods')]
    public function applicationResourceTypeIsRead(): void
    {
        $device = new Device('SN1');
        $device->id = 7;
        $device->status = DeviceStatus::Active;
        $device->weight = 2.5;
        $device->building = 'B1';
        $device->floor = 0;
        $device->checkedBy = 'robot';
        $device->tag = new Tag('t1');
        $device->note = new Tag('n1');

        $mappings = self::mappings(['Device' => Device::class]);

        self::assertSame([
            'schemas' => ['urn:acme:scim:schemas:core:1.0:Device', 'urn:acme:scim:schemas:extension:audit:1.0:Device'],
            'id' => 'd7',
            'status' => 'active',
            'enabled' => true,
            'weight' => 2.5,
            'location' => ['building' => 'B1', 'floor' => 0],
            'serialNumber' => 'SN1',
            'label' => 'SN1',
            'urn:acme:scim:schemas:extension:audit:1.0:Device' => ['checkedBy' => 'robot', 'tag' => 't1', 'note' => 'n1'],
        ], self::mapper($mappings)->toDocument($mappings->get('Device'), $device));
    }

    #[Test]
    #[TestDox('"schemas" lists a required extension without values')]
    public function requiredExtensionIsListed(): void
    {
        $mappings = self::mappings(['Device' => Device::class]);

        $document = self::mapper($mappings)->toDocument($mappings->get('Device'), new Device('SN1'));

        self::assertSame(['urn:acme:scim:schemas:core:1.0:Device', 'urn:acme:scim:schemas:extension:audit:1.0:Device'], $document['schemas'] ?? null);
        self::assertArrayNotHasKey('urn:acme:scim:schemas:extension:audit:1.0:Device', $document);
    }

    #[Test]
    #[TestDox('A writeOnly password is never read; a slot group with two members is one element')]
    public function writeOnlyIsNotRead(): void
    {
        $account = new Account('jdoe', hiredAt: new \DateTime('2026-11-05T12:00:00+02:00'));
        $account->password = 'hash';
        $account->street = 'Main 1';
        $account->postalCode = '1234';

        $mappings = self::mappings(['User' => Account::class]);

        self::assertSame([
            'schemas' => [SchemaUrn::User->value, Account::EXTENSION],
            'id' => 'acc-jdoe',
            'userName' => 'jdoe',
            'displayName' => 'Anonymous',
            'nickName' => 'unused',
            'addresses' => [['streetAddress' => 'Main 1', 'postalCode' => '1234', 'type' => 'work', 'primary' => true]],
            Account::EXTENSION => ['hiredAt' => self::NOW],
        ], self::mapper($mappings)->toDocument($mappings->get('User'), $account));
    }

    #[Test]
    #[TestDox('A collection element that is not an object of the target class fails')]
    public function wrongCollectionElementFails(): void
    {
        $group = new IntGroup();
        new \ReflectionProperty(IntGroup::class, 'users')->setValue($group, [new \stdClass()]);
        $mappings = self::mappings(['User' => IntUser::class, 'Group' => IntGroup::class]);

        $this->expectExceptionObject(new \UnexpectedValueException(\sprintf('%s::$users contains a stdClass, but "members[type eq "User"]" refers to %s.', IntGroup::class, IntUser::class)));

        self::mapper($mappings)->toDocument($mappings->get('Group'), $group);
    }

    private function fullUser(User $manager): User
    {
        $user = new User('bjensen');
        $user->setExternalId('ext-1');
        $user->setFirstName('Barbara');
        $user->setLastName('Jensen');
        $user->setDisplayName('Babs');
        $user->setType(UserType::Employee);
        $user->setEnabled(false);
        $user->setEmail('bjensen@example.com');
        $user->setPhone('555-1');
        $user->setMobile('555-2');
        $user->setEmployeeNumber('701984');
        $user->setDepartment('Tour');
        $user->setManager($manager);

        return $user;
    }
}
