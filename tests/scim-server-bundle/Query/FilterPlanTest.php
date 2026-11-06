<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Query\ListQuery;

final class FilterPlanTest extends KernelTestCase
{
    use PlansQueries;
    private const string ID = '0193a8e2-5c6f-7b4e-9f2a-3c1d2e4f5a6b';

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function fields(): iterable
    {
        yield 'eq' => ['User', 'userName eq "bjensen"', '$userName eq "bjensen"'];
        yield 'ne is not eq' => ['User', 'userName ne "bjensen"', 'not($userName eq "bjensen")'];
        yield 'co' => ['User', 'userName co "jen"', '$userName co "jen"'];
        yield 'sw' => ['User', 'userName sw "bj"', '$userName sw "bj"'];
        yield 'ew' => ['User', 'userName ew "sen"', '$userName ew "sen"'];
        yield 'gt' => ['User', 'userName gt "a"', '$userName gt "a"'];
        yield 'ge' => ['User', 'userName ge "a"', '$userName ge "a"'];
        yield 'lt' => ['User', 'userName lt "a"', '$userName lt "a"'];
        yield 'le' => ['User', 'userName le "a"', '$userName le "a"'];
        yield 'pr' => ['User', 'displayName pr', '$displayName pr'];
        yield 'eq null is not pr' => ['User', 'displayName eq null', 'not($displayName pr)'];
        yield 'ne null is pr' => ['User', 'displayName ne null', '$displayName pr'];
        yield 'empty string converts to null' => ['User', 'displayName eq ""', 'false'];
        yield 'boolean' => ['User', 'active eq "False"', '$enabled eq false'];
        yield 'enum case-insensitive' => ['User', 'userType eq "employee"', '$type eq "Employee"'];
        yield 'enum without case' => ['User', 'userType eq "boss"', 'false'];
        yield 'enum substring literal' => ['User', 'userType sw "emp"', '$type sw "emp"'];
        yield 'enum gt keeps a literal that is no case' => ['User', 'userType gt "C"', '$type gt "C"'];
        yield 'enum lt keeps the literal' => ['User', 'userType lt "employee"', '$type lt "employee"'];
        yield 'gt "" on a nullable string' => ['User', 'displayName gt ""', '$displayName gt ""'];
        yield 'dateTime in UTC' => ['User', 'meta.lastModified gt "2011-05-13T04:42:34+02:00"', '$updatedAt gt 2011-05-13T02:42:34+00:00'];
        yield 'dateTime le' => ['User', 'meta.created le "2011-05-13T04:42:34Z"', '$createdAt le 2011-05-13T04:42:34+00:00'];
        yield 'dateTime eq with a fraction' => ['User', 'meta.lastModified eq "2026-01-10T08:00:00.5Z"', 'false'];
        yield 'dateTime ne with a fraction' => ['User', 'meta.lastModified ne "2026-01-10T08:00:00.5Z"', 'true'];
        yield 'dateTime gt with a fraction' => ['User', 'meta.lastModified gt "2026-01-10T08:00:00.5Z"', '$updatedAt ge 2026-01-10T08:00:01+00:00'];
        yield 'dateTime ge with a fraction' => ['User', 'meta.lastModified ge "2026-01-10T08:00:59.5Z"', '$updatedAt ge 2026-01-10T08:01:00+00:00'];
        yield 'dateTime lt with a fraction' => ['User', 'meta.lastModified lt "2026-01-10T08:00:00.5Z"', '$updatedAt lt 2026-01-10T08:00:01+00:00'];
        yield 'dateTime le with a fraction' => ['User', 'meta.lastModified le "2026-01-10T08:00:00.000001Z"', '$updatedAt lt 2026-01-10T08:00:01+00:00'];
        yield 'uuid id' => ['User', 'id eq "'.self::ID.'"', '$id eq uid:'.self::ID];
        yield 'malformed uuid id' => ['User', 'id eq "abc"', 'false'];
        yield 'ne malformed id' => ['User', 'id ne "abc"', 'true'];
        yield 'externalId' => ['User', 'externalId eq "00u1"', '$externalId eq "00u1"'];
        yield 'complex sub-attribute' => ['User', 'name.givenName sw "Bar"', '$firstName sw "Bar"'];
        yield 'extension attribute' => ['User', 'department eq "Sales"', '$department eq "Sales"'];
        yield 'qualified extension attribute' => ['User', 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:employeeNumber pr', '$employeeNumber pr'];
        yield 'group displayName' => ['Group', 'displayName eq "Admins"', '$name eq "Admins"'];
        yield 'prefixed int id' => ['Device', 'id eq "d42"', '$id eq 42'];
        yield 'int id without prefix' => ['Device', 'id eq "42"', 'false'];
        yield 'int to float' => ['Device', 'weight gt 1', '$weight gt 1.0'];
        yield 'int' => ['Device', 'location.floor ge 2', '$floor ge 2'];
        yield 'application converter' => ['Device', 'tag eq "blue"', '$tag eq tag:blue'];
        yield 'string enum case-insensitive' => ['Device', 'status eq "ACTIVE"', '$status eq "active"'];
        yield 'string enum co' => ['Device', 'status co "tiv"', '$status co "tiv"'];
        yield 'case-exact co' => ['Device', 'serialNumber co "SN"', '$serialNumber co "SN"'];
        yield 'immutable dateTime' => ['Device', 'purchasedAt lt "2026-01-01T00:00:00Z"', '$purchasedAt lt 2026-01-01T00:00:00+00:00'];
        yield 'string id' => ['Contact', 'id eq "c-1"', '$id eq "c-1"'];
        yield 'co on a string id' => ['Contact', 'id co "c-"', '$id co "c-"'];
        yield 'gt on a string id' => ['Contact', 'id gt "c-1"', '$id gt "c-1"'];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function elements(): iterable
    {
        yield 'slot eq' => ['User', 'emails.value eq "a@x"', 'and($email pr, $email eq "a@x")'];
        yield 'slot implicit value' => ['User', 'emails co "@x"', 'and($email pr, $email co "@x")'];
        yield 'slot pr' => ['User', 'emails pr', 'and($email pr, $email pr)'];
        yield 'slot ne: no value or one not equal' => ['User', 'emails.value ne "a@x"', 'or(not(and($email pr, $email pr)), and($email pr, and($email pr, not($email eq "a@x"))))'];
        yield 'slot eq null' => ['User', 'emails.value eq null', 'not(and($email pr, $email pr))'];
        yield 'type of the group' => ['User', 'emails.type eq "work"', '$email pr'];
        yield 'type of no group' => ['User', 'emails.type eq "home"', 'false'];
        yield 'type ne' => ['User', 'emails.type ne "work"', 'not($email pr)'];
        yield 'type pr' => ['User', 'emails.type pr', '$email pr'];
        yield 'primary of the primary group' => ['User', 'emails.primary eq true', '$email pr'];
        yield 'primary false' => ['User', 'emails.primary eq false', 'false'];
        yield 'two groups' => ['User', 'phoneNumbers.value eq "1"', 'or(and($phone pr, $phone eq "1"), and($mobile pr, $mobile eq "1"))'];
        yield 'primary of two groups' => ['User', 'phoneNumbers.primary eq true', '$phone pr'];
        yield 'two groups ne' => ['User', 'phoneNumbers.value ne "1"', 'or(not(or(and($phone pr, $phone pr), and($mobile pr, $mobile pr))), or(and($phone pr, and($phone pr, not($phone eq "1"))), and($mobile pr, and($mobile pr, not($mobile eq "1")))))'];
        yield 'type ne of two groups' => ['User', 'phoneNumbers.type ne "work"', 'or(not(or($phone pr, $mobile pr)), $mobile pr)'];
        yield 'type co' => ['User', 'phoneNumbers.type co "o"', 'or($phone pr, $mobile pr)'];
        yield 'type case-insensitive' => ['User', 'phoneNumbers.type eq "Mobile"', '$mobile pr'];
        yield 'value path type' => ['User', 'emails[type eq "work"]', '$email pr'];
        yield 'value path and' => ['User', 'emails[type eq "work" and value co "@x"]', 'and($email pr, $email co "@x")'];
        yield 'value path Entra form' => ['User', 'emails[type eq "work"].value eq "a@x"', 'and($email pr, $email eq "a@x")'];
        yield 'value path other type' => ['User', 'emails[type eq "home"]', 'false'];
        yield 'value path not' => ['User', 'emails[not (type eq "work")]', 'false'];
        yield 'value path or over groups' => ['User', 'phoneNumbers[type eq "mobile" or primary eq true]', 'or($phone pr, $mobile pr)'];
        yield 'value path ne per element' => ['User', 'phoneNumbers[value ne "1"]', 'or(and($phone pr, not($phone eq "1")), and($mobile pr, not($mobile eq "1")))'];
        yield 'group of two members' => ['Contact', 'addresses[type eq "work"]', 'or($workLocality pr, $workPostalCode pr)'];
        yield 'sub-attribute of one group' => ['Contact', 'addresses.region eq "NSW"', 'and(or($homeLocality pr, $homeRegion pr), $homeRegion eq "NSW")'];
        yield 'complex pr over groups' => ['Contact', 'addresses pr', 'or(or($workLocality pr, $workPostalCode pr), or($homeLocality pr, $homeRegion pr))'];
        yield 'complex pr' => ['User', 'name pr', 'or($firstName pr, $lastName pr, getFormattedName() pr)'];
        yield 'complex value path' => ['User', 'name[givenName eq "Barbara"]', 'and(or($firstName pr, $lastName pr, getFormattedName() pr), $firstName eq "Barbara")'];
        yield 'complex value path ne' => ['User', 'name[givenName ne "x"]', 'and(or($firstName pr, $lastName pr, getFormattedName() pr), not($firstName eq "x"))'];
        yield 'meta pr' => ['User', 'meta pr', 'or($createdAt pr, $updatedAt pr, $version pr)'];
        yield 'application complex pr' => ['Device', 'location pr', 'or($building pr, $floor pr)'];
        yield 'writeOnly sub-attribute renders nothing' => ['Contact', 'badge pr', '$badgeHolder pr'];
        yield 'value path beside a writeOnly sub-attribute' => ['Contact', 'badge[holder eq "x"]', 'and($badgeHolder pr, $badgeHolder eq "x")'];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function relations(): iterable
    {
        yield 'reference value' => ['User', 'manager.value eq "'.self::ID.'"', 'exists($manager, $id eq uid:'.self::ID.')'];
        yield 'reference implicit value' => ['User', 'manager eq "'.self::ID.'"', 'exists($manager, $id eq uid:'.self::ID.')'];
        yield 'reference malformed id' => ['User', 'manager eq "x"', 'false'];
        yield 'reference ne' => ['User', 'manager.value ne "'.self::ID.'"', 'not(exists($manager, $id eq uid:'.self::ID.'))'];
        yield 'reference pr' => ['User', 'manager pr', 'exists($manager)'];
        yield 'reference eq null' => ['User', 'manager eq null', 'not(exists($manager))'];
        yield 'reference value path' => ['User', 'manager[value eq "'.self::ID.'"]', 'exists($manager, $id eq uid:'.self::ID.')'];
        yield 'reference value path pr' => ['User', 'manager[value pr]', 'exists($manager)'];
        yield 'reference value path not' => ['User', 'manager[not (value eq "'.self::ID.'")]', 'exists($manager, not($id eq uid:'.self::ID.'))'];
        yield 'untyped collection' => ['User', 'groups.value eq "'.self::ID.'"', 'exists($groups, $id eq uid:'.self::ID.')'];
        yield 'untyped collection pr' => ['User', 'groups pr', 'exists($groups)'];
        yield 'untyped collection ne' => ['User', 'groups.value ne "'.self::ID.'"', 'or(not(exists($groups)), exists($groups, not($id eq uid:'.self::ID.')))'];
        yield 'collection malformed id' => ['User', 'groups[value eq "x"]', 'false'];
        yield 'collections value' => ['Group', 'members.value eq "'.self::ID.'"', 'or(exists($users, $id eq uid:'.self::ID.'), exists($subgroups, $id eq uid:'.self::ID.'))'];
        yield 'collections malformed id' => ['Group', 'members eq "x"', 'false'];
        yield 'collections ne' => ['Group', 'members.value ne "'.self::ID.'"', 'or(not(or(exists($users), exists($subgroups))), or(exists($users, not($id eq uid:'.self::ID.')), exists($subgroups, not($id eq uid:'.self::ID.'))))'];
        yield 'collections ne malformed id' => ['Group', 'members ne "x"', 'or(not(or(exists($users), exists($subgroups))), or(exists($users), exists($subgroups)))'];
        yield 'collections pr' => ['Group', 'members pr', 'or(exists($users), exists($subgroups))'];
        yield 'collection type' => ['Group', 'members.type eq "Group"', 'exists($subgroups)'];
        yield 'collection type of none' => ['Group', 'members.type eq "Device"', 'false'];
        yield 'collection type ne' => ['Group', 'members.type ne "User"', 'or(not(or(exists($users), exists($subgroups))), exists($subgroups))'];
        yield 'collection type pr' => ['Group', 'members.type pr', 'or(exists($users), exists($subgroups))'];
        yield 'collection value path and' => ['Group', 'members[type eq "User" and value eq "'.self::ID.'"]', 'exists($users, $id eq uid:'.self::ID.')'];
        yield 'collection value path or' => ['Group', 'members[type eq "User" or value eq "'.self::ID.'"]', 'or(exists($users), exists($subgroups, $id eq uid:'.self::ID.'))'];
        yield 'collection value path not' => ['Group', 'members[not (type eq "User")]', 'exists($subgroups)'];
        yield 'Entra membership check' => ['Group', 'id eq "'.self::ID.'" and members eq "'.self::ID.'"', 'and($id eq uid:'.self::ID.', or(exists($users, $id eq uid:'.self::ID.'), exists($subgroups, $id eq uid:'.self::ID.')))'];
        yield 'typed beside untyped collection' => ['Contact', 'groups.type eq "direct"', 'exists($directGroups)'];
        yield 'type pr skips the untyped collection' => ['Contact', 'groups.type pr', 'exists($directGroups)'];
        yield 'typed and untyped collection value' => ['Contact', 'groups.value eq "'.self::ID.'"', 'or(exists($directGroups, $id eq uid:'.self::ID.'), exists($otherGroups, $id eq uid:'.self::ID.'))'];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function logic(): iterable
    {
        yield 'and' => ['User', 'userName eq "a" and displayName eq "b"', 'and($userName eq "a", $displayName eq "b")'];
        yield 'or' => ['User', 'userName eq "a" or displayName eq "b"', 'or($userName eq "a", $displayName eq "b")'];
        yield 'not' => ['User', 'not (userName eq "a")', 'not($userName eq "a")'];
        yield 'precedence' => ['User', 'userName pr and displayName pr or externalId pr', 'or(and($userName pr, $displayName pr), $externalId pr)'];
        yield 'nested' => ['User', '(userName eq "a" or userName eq "b") and not (active eq false)', 'and(or($userName eq "a", $userName eq "b"), not($enabled eq false))'];
        yield 'and with false' => ['User', 'userName eq "a" and id eq "x"', 'false'];
        yield 'or with false' => ['User', 'userName eq "a" or id eq "x"', '$userName eq "a"'];
        yield 'and with true' => ['User', 'userName eq "a" and id ne "x"', '$userName eq "a"'];
        yield 'or with true' => ['User', 'userName eq "a" or id ne "x"', 'true'];
        yield 'not false' => ['User', 'not (id eq "x")', 'true'];
        yield 'not true' => ['User', 'not (id ne "x")', 'false'];
    }

    #[Test]
    #[DataProvider('fields')]
    #[TestDox('Field: $filter on $name plans as $expected')]
    public function field(string $name, string $filter, string $expected): void
    {
        $this->assertPlan($name, $filter, $expected);
    }

    #[Test]
    #[DataProvider('elements')]
    #[TestDox('Element: $filter on $name plans as $expected')]
    public function element(string $name, string $filter, string $expected): void
    {
        $this->assertPlan($name, $filter, $expected);
    }

    #[Test]
    #[DataProvider('relations')]
    #[TestDox('Relation: $filter on $name plans as $expected')]
    public function relation(string $name, string $filter, string $expected): void
    {
        $this->assertPlan($name, $filter, $expected);
    }

    #[Test]
    #[DataProvider('logic')]
    #[TestDox('Logic: $filter plans as $expected')]
    public function logical(string $name, string $filter, string $expected): void
    {
        $this->assertPlan($name, $filter, $expected);
    }

    private function assertPlan(string $name, string $filter, string $expected): void
    {
        self::assertSame($expected, PlanText::of($this->plan(new ListQuery($this->resolved($filter, $name)), $name)));
    }
}
