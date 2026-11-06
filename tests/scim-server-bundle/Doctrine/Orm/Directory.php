<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\UserAddress;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Enum\UserType;

/**
 * Four users and three groups in the database, and their SCIM documents, built by hand with the read rules of
 * contracts 6.3: null and "" are absent.
 *
 * | user    | externalId | givenName | familyName | displayName  | userType   | active | work email          | work phone | mobile   | department      | manager |
 * | bjensen | ext-1      | Barbara   | Jensen     | Babs Jensen  | Employee   | true   | bjensen@example.com | 555-0101   |          | Tour Operations |         |
 * | jsmith  | EXT-2      | John      | Smith      | John Smith   | Contractor | false  | J.Smith@Example.org |            | 555-0202 | Sales           | bjensen |
 * | omalley |            |           | O'Malley   | 50% off_x!   |            | true   | ""                  |            |          |                 | bjensen |
 * | alice   |            | alice     |            | 5000 Bax!    | Intern     | true   |                     | 555-0404   | 555-0405 | ""              | jsmith  |
 *
 * Groups: Staff (bjensen, jsmith, subgroup Sales), Sales (jsmith), Empty.
 *
 * Addresses, in id order: bjensen home Berlin DE, work 100 Main St Paris 75001 FR primary; jsmith other Lyon, home
 * Amsterdam; omalley one with locality "" and no type; alice home Hamburg.
 */
final readonly class Directory
{
    public const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    /**
     * @param array<string, Uuid> $ids by user name and group name
     */
    private function __construct(
        public array $ids,
    ) {
    }

    public static function seed(EntityManagerInterface $entityManager): self
    {
        $bjensen = self::user('bjensen', 'ext-1', 'Barbara', 'Jensen', 'Babs Jensen', UserType::Employee, '2026-01-10T08:00:00Z');
        $bjensen->setEmail('bjensen@example.com');
        $bjensen->setPhone('555-0101');
        $bjensen->setEmployeeNumber('701984');
        $bjensen->setDepartment('Tour Operations');

        $jsmith = self::user('jsmith', 'EXT-2', 'John', 'Smith', 'John Smith', UserType::Contractor, '2026-02-10T08:00:00Z');
        $jsmith->setEnabled(false);
        $jsmith->setEmail('J.Smith@Example.org');
        $jsmith->setMobile('555-0202');
        $jsmith->setDepartment('Sales');
        $jsmith->setManager($bjensen);

        $omalley = self::user('omalley', null, null, "O'Malley", '50% off_x!', null, '2026-03-10T08:00:00Z');
        $omalley->setEmail('');
        $omalley->setManager($bjensen);

        $alice = self::user('alice', null, 'alice', null, '5000 Bax!', UserType::Intern, '2026-01-01T08:00:00Z');
        $alice->setPhone('555-0404');
        $alice->setMobile('555-0405');
        $alice->setDepartment('');
        $alice->setManager($jsmith);

        self::address($bjensen, 'home', 'Berlin', 'DE');
        self::address($bjensen, 'work', 'Paris', 'FR', '100 Main St', '75001', true);
        self::address($jsmith, 'other', 'Lyon');
        self::address($jsmith, 'home', 'Amsterdam');
        self::address($omalley, null, '');
        self::address($alice, 'home', 'Hamburg');

        $staff = new Group('Staff');
        $staff->setExternalId('g-1');
        $staff->addUser($bjensen);
        $staff->addUser($jsmith);

        $sales = new Group('Sales');
        $sales->addUser($jsmith);

        $staff->addSubgroup($sales);
        $empty = new Group('Empty');

        $users = [$bjensen, $jsmith, $omalley, $alice];
        $groups = [$staff, $sales, $empty];
        array_map($entityManager->persist(...), [...$users, ...$groups]);
        $entityManager->flush();
        $entityManager->clear();

        return new self([
            ...array_column(array_map(static fn (User $user): array => [$user->getUserName(), $user->getId()], $users), 1, 0),
            ...array_column(array_map(static fn (Group $group): array => [$group->getName(), $group->getId()], $groups), 1, 0),
        ]);
    }

    public function id(string $name): Uuid
    {
        return $this->ids[$name] ?? throw new \LogicException(\sprintf('No user or group "%s".', $name));
    }

    /**
     * @return array<string, string> "{<name>}" => SCIM id
     */
    public function placeholders(): array
    {
        return array_combine(array_map(static fn (string $name): string => '{'.$name.'}', array_keys($this->ids)), array_map(strval(...), $this->ids));
    }

    /**
     * @return array<string, mixed>
     */
    public static function userDocument(User $user): array
    {
        $manager = $user->getManager();

        return self::withoutAbsent([
            'id' => (string) $user->getId(),
            'externalId' => $user->getExternalId(),
            'userName' => $user->getUserName(),
            'name' => ['givenName' => $user->getFirstName(), 'familyName' => $user->getLastName(), 'formatted' => $user->getFormattedName()],
            'displayName' => $user->getDisplayName(),
            'userType' => $user->getType()?->value,
            'active' => $user->isEnabled(),
            'emails' => [self::element($user->getEmail(), 'work', true)],
            'phoneNumbers' => [self::element($user->getPhone(), 'work', true), self::element($user->getMobile(), 'mobile', false)],
            'addresses' => array_map(static fn (UserAddress $address): array => [
                'streetAddress' => $address->getStreet(),
                'locality' => $address->getLocality(),
                'postalCode' => $address->getPostalCode(),
                'country' => $address->getCountry(),
                'type' => $address->getType(),
                'primary' => $address->isPrimary(),
            ], $user->getAddresses()->getValues()),
            'groups' => array_map(static fn (Group $group): array => ['value' => (string) $group->getId(), 'display' => $group->getName()], $user->getGroups()->getValues()),
            'meta' => ['created' => $user->getCreatedAt()->format(\DATE_ATOM), 'lastModified' => $user->getUpdatedAt()->format(\DATE_ATOM)],
            self::ENTERPRISE => [
                'employeeNumber' => $user->getEmployeeNumber(),
                'department' => $user->getDepartment(),
                'manager' => $manager instanceof User ? ['value' => (string) $manager->getId(), 'displayName' => $manager->getDisplayName()] : null,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function groupDocument(Group $group): array
    {
        return self::withoutAbsent([
            'id' => (string) $group->getId(),
            'externalId' => $group->getExternalId(),
            'displayName' => $group->getName(),
            'members' => [
                ...array_map(static fn (User $user): array => ['value' => (string) $user->getId(), 'display' => $user->getDisplayName(), 'type' => 'User'], $group->getUsers()->getValues()),
                ...array_map(static fn (Group $subgroup): array => ['value' => (string) $subgroup->getId(), 'display' => $subgroup->getName(), 'type' => 'Group'], $group->getSubgroups()->getValues()),
            ],
            'meta' => ['created' => $group->getCreatedAt()->format(\DATE_ATOM), 'lastModified' => $group->getUpdatedAt()->format(\DATE_ATOM)],
        ]);
    }

    private static function user(string $userName, ?string $externalId, ?string $givenName, ?string $familyName, string $displayName, ?UserType $type, string $lastModified): User
    {
        $user = new User($userName);
        $user->setExternalId($externalId);
        $user->setFirstName($givenName);
        $user->setLastName($familyName);
        $user->setDisplayName($displayName);
        $user->setType($type);
        $user->setUpdatedAt(new \DateTimeImmutable($lastModified));

        return $user;
    }

    private static function address(User $user, ?string $type, string $locality, ?string $country = null, ?string $street = null, ?string $postalCode = null, bool $primary = false): void
    {
        $address = new UserAddress($user, $type);
        $address->setLocality($locality);
        $address->setCountry($country);
        $address->setStreet($street);
        $address->setPostalCode($postalCode);
        $address->setPrimary($primary);

        $user->addAddress($address);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function element(?string $value, string $type, bool $primary): ?array
    {
        return null === $value || '' === $value ? null : ['value' => $value, 'type' => $type, ...($primary ? ['primary' => true] : [])];
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private static function withoutAbsent(array $values): array
    {
        $kept = array_filter(
            array_map(static fn (mixed $value): mixed => \is_array($value) ? self::withoutAbsent($value) : $value, $values),
            static fn (mixed $value): bool => !\in_array($value, [null, '', []], true),
        );

        return array_is_list($values) ? array_values($kept) : $kept;
    }
}
