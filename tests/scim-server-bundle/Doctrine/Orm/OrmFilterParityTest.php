<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Filter\FilterEvaluator;
use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Filter\FilterResolver;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;
use TacticMedia\ScimServerBundle\Query\Plan\Node;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;

/**
 * The ORM store and the library FilterEvaluator, on the same data, match the same resources. Each plan is built by
 * hand and equals the plan of the query planner service.
 */
final class OrmFilterParityTest extends KernelTestCase
{
    use CreatesOrmStore;

    /**
     * @param \Closure(Plans): Node $plan
     * @param list<string>          $expected user names or group names, sorted
     */
    #[Test]
    #[DataProvider('filters')]
    #[TestDox('$filter matches the same $resource resources as the library evaluator')]
    public function storeMatchesTheEvaluator(string $resource, string $filter, \Closure $plan, array $expected): void
    {
        $entityManager = self::createSchema();
        if ($entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $entityManager->getConnection()->executeStatement('PRAGMA case_sensitive_like = ON');
        }

        $directory = Directory::seed($entityManager);
        $plans = new Plans(self::mappings(), $resource, $directory);
        $mapping = $plans->mapping();
        $resolved = new FilterResolver()->resolve(new FilterParser()->parse(strtr($filter, $directory->placeholders())), $mapping->resourceType);
        $planned = self::getContainer()->get('tacticmedia_scim_server.query_planner')->plan(new ListQuery($resolved), $mapping);
        self::assertInstanceOf(ObjectQuery::class, $planned);
        self::assertEquals($plan($plans), $planned->filter);

        $stored = self::store()->query($mapping, new ObjectQuery($plan($plans), [], SortOrder::Ascending, 1, 100))->objects;
        $documents = array_map(
            static fn (object $object): array => $object instanceof User ? Directory::userDocument($object) : Directory::groupDocument(self::group($object)),
            $entityManager->getRepository($mapping->class)->findAll(),
        );
        $evaluated = array_values(array_filter($documents, static fn (array $document): bool => new FilterEvaluator()->matches($resolved, $document, $mapping->resourceType)));

        self::assertSame($expected, $this->sorted(array_map(static fn (array $document): mixed => $document['userName'] ?? $document['displayName'], $evaluated)));
        self::assertSame($this->sorted(array_column($evaluated, 'id')), $this->sorted(array_map(static fn (object $object): string => $object instanceof User ? (string) $object->getId() : (string) self::group($object)->getId(), $stored)));
    }

    /**
     * @return iterable<string, array{string, string, \Closure(Plans): Node, list<string>}>
     */
    public static function filters(): iterable
    {
        yield from self::userFieldFilters();
        yield from self::userSlotAndReferenceFilters();
        yield from self::userLogicalFilters();
        yield from self::userElementAndExpressionFilters();
        yield from self::groupFilters();
    }

    /**
     * @return iterable<string, array{string, string, \Closure(Plans): Node, list<string>}>
     */
    private static function userElementAndExpressionFilters(): iterable
    {
        yield 'addresses.locality eq, case-insensitive' => ['User', 'addresses.locality eq "PARIS"', static fn (Plans $p): Node => $p->exists('addresses', $p->eq('addresses.locality', 'PARIS')), ['bjensen']];
        yield 'addresses value path, no element has both' => ['User', 'addresses[country eq "fr" and type eq "home"]', static fn (Plans $p): Node => $p->exists('addresses', $p->all($p->eq('addresses.country', 'fr'), $p->eq('addresses.type', 'home'))), []];
        yield 'addresses, two elements have one each' => ['User', 'addresses.country eq "fr" and addresses.type eq "home"', static fn (Plans $p): Node => $p->all($p->exists('addresses', $p->eq('addresses.country', 'fr')), $p->exists('addresses', $p->eq('addresses.type', 'home'))), ['bjensen']];
        yield 'addresses value path not, a type absent' => ['User', 'addresses[not (type eq "home")]', static fn (Plans $p): Node => $p->exists('addresses', $p->not($p->eq('addresses.type', 'home'))), ['bjensen', 'jsmith', 'omalley']];
        yield 'addresses.locality ne, empty string absent' => ['User', 'addresses.locality ne "Hamburg"', static fn (Plans $p): Node => $p->any(
            $p->not($p->exists('addresses', $p->present('addresses.locality'))),
            $p->exists('addresses', $p->all($p->present('addresses.locality'), $p->not($p->eq('addresses.locality', 'Hamburg')))),
        ), ['bjensen', 'jsmith', 'omalley']];
        yield 'addresses pr, an element with only primary false' => ['User', 'addresses pr', static fn (Plans $p): Node => $p->exists('addresses'), ['alice', 'bjensen', 'jsmith', 'omalley']];
        yield 'name.formatted sw, without givenName' => ['User', 'name.formatted sw "o\'"', static fn (Plans $p): Node => $p->compare('name.formatted', ComparisonOperator::StartsWith, "o'"), ['omalley']];
        yield 'name.formatted ne' => ['User', 'name.formatted ne "John Smith"', static fn (Plans $p): Node => $p->not($p->eq('name.formatted', 'John Smith')), ['alice', 'bjensen', 'omalley']];
        yield 'name value path with a DQL expression' => ['User', 'name[formatted ew "smith" or givenName eq "alice"]', static fn (Plans $p): Node => $p->all(
            $p->any($p->present('name.givenName'), $p->present('name.familyName'), $p->present('name.formatted')),
            $p->any($p->compare('name.formatted', ComparisonOperator::EndsWith, 'smith'), $p->eq('name.givenName', 'alice')),
        ), ['alice', 'jsmith']];
    }

    /**
     * @return iterable<string, array{string, string, \Closure(Plans): Node, list<string>}>
     */
    private static function userFieldFilters(): iterable
    {
        yield 'userName eq, case-insensitive' => ['User', 'userName eq "BJENSEN"', static fn (Plans $p): Node => $p->eq('userName', 'BJENSEN'), ['bjensen']];
        yield 'externalId eq, case-exact miss' => ['User', 'externalId eq "ext-2"', static fn (Plans $p): Node => $p->eq('externalId', 'ext-2'), []];
        yield 'externalId eq, case-exact hit' => ['User', 'externalId eq "EXT-2"', static fn (Plans $p): Node => $p->eq('externalId', 'EXT-2'), ['jsmith']];
        yield 'externalId co, case-exact' => ['User', 'externalId co "XT"', static fn (Plans $p): Node => $p->compare('externalId', ComparisonOperator::Contains, 'XT'), ['jsmith']];
        yield 'externalId eq null' => ['User', 'externalId eq null', static fn (Plans $p): Node => $p->not($p->present('externalId')), ['alice', 'omalley']];
        yield 'displayName co escapes %' => ['User', 'displayName co "50%"', static fn (Plans $p): Node => $p->compare('displayName', ComparisonOperator::Contains, '50%'), ['omalley']];
        yield 'displayName co escapes _ and !' => ['User', 'displayName co "_x!"', static fn (Plans $p): Node => $p->compare('displayName', ComparisonOperator::Contains, '_x!'), ['omalley']];
        yield 'givenName gt, case-folded' => ['User', 'name.givenName gt "C"', static fn (Plans $p): Node => $p->compare('name.givenName', ComparisonOperator::GreaterThan, 'C'), ['jsmith']];
        yield 'givenName ge' => ['User', 'name.givenName ge "barbara"', static fn (Plans $p): Node => $p->compare('name.givenName', ComparisonOperator::GreaterThanOrEqual, 'barbara'), ['bjensen', 'jsmith']];
        yield 'givenName le' => ['User', 'name.givenName le "BARBARA"', static fn (Plans $p): Node => $p->compare('name.givenName', ComparisonOperator::LessThanOrEqual, 'BARBARA'), ['alice', 'bjensen']];
        yield 'lastModified le, equal bound' => ['User', 'meta.lastModified le "2026-01-10T08:00:00Z"', static fn (Plans $p): Node => $p->compare('meta.lastModified', ComparisonOperator::LessThanOrEqual, new \DateTimeImmutable('2026-01-10T08:00:00Z')), ['alice', 'bjensen']];
        yield 'lastModified gt, a fraction' => ['User', 'meta.lastModified gt "2026-01-10T08:00:00.5Z"', static fn (Plans $p): Node => $p->compare('meta.lastModified', ComparisonOperator::GreaterThanOrEqual, new \DateTimeImmutable('2026-01-10T08:00:01Z')), ['jsmith', 'omalley']];
        yield 'lastModified le, a fraction' => ['User', 'meta.lastModified le "2026-01-10T08:00:00.5Z"', static fn (Plans $p): Node => $p->compare('meta.lastModified', ComparisonOperator::LessThan, new \DateTimeImmutable('2026-01-10T08:00:01Z')), ['alice', 'bjensen']];
        yield 'active eq false' => ['User', 'active eq false', static fn (Plans $p): Node => $p->eq('active', false), ['jsmith']];
        yield 'displayName gt ""' => ['User', 'displayName gt ""', static fn (Plans $p): Node => $p->compare('displayName', ComparisonOperator::GreaterThan, ''), ['alice', 'bjensen', 'jsmith', 'omalley']];
        yield 'department pr, empty string absent' => ['User', 'department pr', static fn (Plans $p): Node => $p->present('department'), ['bjensen', 'jsmith']];
        yield 'department ne' => ['User', 'department ne "Sales"', static fn (Plans $p): Node => $p->not($p->eq('department', 'Sales')), ['alice', 'bjensen', 'omalley']];
        yield 'department lt, empty string absent' => ['User', 'department lt "b"', static fn (Plans $p): Node => $p->compare('department', ComparisonOperator::LessThan, 'b'), []];
        yield 'department co "", empty string absent' => ['User', 'department co ""', static fn (Plans $p): Node => $p->compare('department', ComparisonOperator::Contains, ''), ['bjensen', 'jsmith']];
        yield 'id eq' => ['User', 'id eq "{jsmith}"', static fn (Plans $p): Node => $p->idOf('User', 'jsmith'), ['jsmith']];
    }

    /**
     * @return iterable<string, array{string, string, \Closure(Plans): Node, list<string>}>
     */
    private static function userSlotAndReferenceFilters(): iterable
    {
        $phones = static fn (Plans $p, \Closure $condition): Node => $p->any(
            $p->all($p->present('phoneNumbers.value', 'work'), $condition('work')),
            $p->all($p->present('phoneNumbers.value', 'mobile'), $condition('mobile')),
        );
        $phonesNotEqual = static fn (Plans $p, string $value): Node => $p->any(
            $p->not($phones($p, static fn (string $type): Node => $p->present('phoneNumbers.value', $type))),
            $phones($p, static fn (string $type): Node => $p->all($p->present('phoneNumbers.value', $type), $p->not($p->eq('phoneNumbers.value', $value, $type)))),
        );

        yield 'emails pr, empty string absent' => ['User', 'emails pr', static fn (Plans $p): Node => $p->all($p->present('emails.value'), $p->present('emails.value')), ['bjensen', 'jsmith']];
        yield 'emails value path' => ['User', 'emails[type eq "work" and value ew ".org"]', static fn (Plans $p): Node => $p->all($p->present('emails.value'), $p->compare('emails.value', ComparisonOperator::EndsWith, '.org')), ['jsmith']];
        yield 'phoneNumbers.value eq, two slots' => ['User', 'phoneNumbers.value eq "555-0405"', static fn (Plans $p): Node => $p->any(
            $p->all($p->present('phoneNumbers.value', 'work'), $p->eq('phoneNumbers.value', '555-0405', 'work')),
            $p->all($p->present('phoneNumbers.value', 'mobile'), $p->eq('phoneNumbers.value', '555-0405', 'mobile')),
        ), ['alice']];
        yield 'phoneNumbers.value ne, one of two values equal' => ['User', 'phoneNumbers.value ne "555-0405"', static fn (Plans $p): Node => $phonesNotEqual($p, '555-0405'), ['alice', 'bjensen', 'jsmith', 'omalley']];
        yield 'phoneNumbers value path ne' => ['User', 'phoneNumbers[value ne "555-0405"]', static fn (Plans $p): Node => $p->any(
            $p->all($p->present('phoneNumbers.value', 'work'), $p->not($p->eq('phoneNumbers.value', '555-0405', 'work'))),
            $p->all($p->present('phoneNumbers.value', 'mobile'), $p->not($p->eq('phoneNumbers.value', '555-0405', 'mobile'))),
        ), ['alice', 'bjensen', 'jsmith']];
        yield 'phoneNumbers.primary eq true' => ['User', 'phoneNumbers.primary eq true', static fn (Plans $p): Node => $p->present('phoneNumbers.value', 'work'), ['alice', 'bjensen']];
        yield 'manager.value eq' => ['User', 'manager.value eq "{bjensen}"', static fn (Plans $p): Node => $p->exists('manager', $p->idOf('User', 'bjensen')), ['jsmith', 'omalley']];
        yield 'not manager pr' => ['User', 'not (manager pr)', static fn (Plans $p): Node => $p->not($p->exists('manager')), ['bjensen']];
        yield 'groups.value eq' => ['User', 'groups.value eq "{Staff}"', static fn (Plans $p): Node => $p->exists('groups', $p->idOf('Group', 'Staff')), ['bjensen', 'jsmith']];
        yield 'groups.value ne' => ['User', 'groups.value ne "{Sales}"', static fn (Plans $p): Node => $p->any(
            $p->not($p->exists('groups')),
            $p->exists('groups', $p->not($p->idOf('Group', 'Sales'))),
        ), ['alice', 'bjensen', 'jsmith', 'omalley']];
    }

    /**
     * @return iterable<string, array{string, string, \Closure(Plans): Node, list<string>}>
     */
    private static function userLogicalFilters(): iterable
    {
        yield 'not of or' => ['User', 'not (userName eq "bjensen" or department pr)', static fn (Plans $p): Node => $p->not($p->any($p->eq('userName', 'bjensen'), $p->present('department'))), ['alice', 'omalley']];
        yield 'and, or and not' => ['User', '(userType eq "Intern" or active eq false) and not (emails pr)', static fn (Plans $p): Node => $p->all(
            $p->any($p->eq('userType', 'Intern'), $p->eq('active', false)),
            $p->not($p->all($p->present('emails.value'), $p->present('emails.value'))),
        ), ['alice']];
    }

    /**
     * @return iterable<string, array{string, string, \Closure(Plans): Node, list<string>}>
     */
    private static function groupFilters(): iterable
    {
        $members = static fn (Plans $p, string $name): Node => $p->any($p->exists('members', $p->idOf('User', $name), 'User'), $p->exists('members', $p->idOf('Group', $name), 'Group'));
        $anyMember = static fn (Plans $p): Node => $p->any($p->exists('members', null, 'User'), $p->exists('members', null, 'Group'));
        $membersNotEqual = static fn (Plans $p, string $name): Node => $p->any(
            $p->not($anyMember($p)),
            $p->any($p->exists('members', $p->not($p->idOf('User', $name)), 'User'), $p->exists('members', $p->not($p->idOf('Group', $name)), 'Group')),
        );

        yield 'group displayName sw' => ['Group', 'displayName sw "s"', static fn (Plans $p): Node => $p->compare('displayName', ComparisonOperator::StartsWith, 's'), ['Sales', 'Staff']];
        yield 'members.value eq a user' => ['Group', 'members.value eq "{jsmith}"', static fn (Plans $p): Node => $members($p, 'jsmith'), ['Sales', 'Staff']];
        yield 'members.value ne, the one member equal' => ['Group', 'members.value ne "{jsmith}"', static fn (Plans $p): Node => $membersNotEqual($p, 'jsmith'), ['Empty', 'Staff']];
        yield 'members typed value path' => ['Group', 'members[type eq "User" and value eq "{bjensen}"]', static fn (Plans $p): Node => $p->exists('members', $p->idOf('User', 'bjensen'), 'User'), ['Staff']];
        yield 'members.type eq' => ['Group', 'members.type eq "Group"', static fn (Plans $p): Node => $p->exists('members', null, 'Group'), ['Staff']];
        yield 'not members pr' => ['Group', 'not (members pr)', static fn (Plans $p): Node => $p->not($anyMember($p)), ['Empty']];
    }

    private static function group(object $object): Group
    {
        self::assertInstanceOf(Group::class, $object);

        return $object;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $strings = array_map(strval(...), array_values($values));
        sort($strings);

        return $strings;
    }
}
