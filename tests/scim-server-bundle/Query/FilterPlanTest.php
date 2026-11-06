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
        yield 'pr' => ['User', 'displayName pr', '$displayName pr'];
        yield 'eq null is not pr' => ['User', 'displayName eq null', 'not($displayName pr)'];
        yield 'ne null is pr' => ['User', 'displayName ne null', '$displayName pr'];
        yield 'empty string converts to null' => ['User', 'displayName eq ""', 'false'];
        yield 'boolean' => ['User', 'active eq "False"', '$enabled eq false'];
        yield 'enum case-insensitive' => ['User', 'userType eq "employee"', '$type eq "Employee"'];
        yield 'enum substring literal' => ['User', 'userType sw "emp"', '$type sw "emp"'];
        yield 'enum lt keeps the literal' => ['User', 'userType lt "employee"', '$type lt "employee"'];
        yield 'gt "" on a nullable string' => ['User', 'displayName gt ""', '$displayName gt ""'];
        yield 'dateTime in UTC' => ['User', 'meta.lastModified gt "2011-05-13T04:42:34+02:00"', '$updatedAt gt 2011-05-13T02:42:34+00:00'];
        yield 'dateTime eq with a fraction' => ['User', 'meta.lastModified eq "2026-01-10T08:00:00.5Z"', 'false'];
        yield 'dateTime gt with a fraction' => ['User', 'meta.lastModified gt "2026-01-10T08:00:00.5Z"', '$updatedAt ge 2026-01-10T08:00:01+00:00'];
        yield 'dateTime ge with a fraction' => ['User', 'meta.lastModified ge "2026-01-10T08:00:59.5Z"', '$updatedAt ge 2026-01-10T08:01:00+00:00'];
        yield 'dateTime le with a fraction' => ['User', 'meta.lastModified le "2026-01-10T08:00:00.000001Z"', '$updatedAt lt 2026-01-10T08:00:01+00:00'];
        yield 'uuid id' => ['User', 'id eq "'.self::ID.'"', '$id eq uid:'.self::ID];
        yield 'malformed uuid id' => ['User', 'id eq "abc"', 'false'];
        yield 'ne malformed id' => ['User', 'id ne "abc"', 'true'];
        yield 'int to float' => ['Device', 'weight gt 1', '$weight gt 1.0'];
        yield 'application converter' => ['Device', 'tag eq "blue"', '$tag eq tag:blue'];
        yield 'co on a string id' => ['Contact', 'id co "c-"', '$id co "c-"'];
        yield 'gt on a string id' => ['Contact', 'id gt "c-1"', '$id gt "c-1"'];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function elements(): iterable
    {
        yield 'slot eq' => ['User', 'emails.value eq "a@x"', 'and($email pr, $email eq "a@x")'];
        yield 'slot ne: no value or one not equal' => ['User', 'emails.value ne "a@x"', 'or(not(and($email pr, $email pr)), and($email pr, and($email pr, not($email eq "a@x"))))'];
        yield 'slot eq null' => ['User', 'emails.value eq null', 'not(and($email pr, $email pr))'];
        yield 'type of the group' => ['User', 'emails.type eq "work"', '$email pr'];
        yield 'type of no group' => ['User', 'emails.type eq "home"', 'false'];
        yield 'two groups' => ['User', 'phoneNumbers.value eq "1"', 'or(and($phone pr, $phone eq "1"), and($mobile pr, $mobile eq "1"))'];
        yield 'primary of two groups' => ['User', 'phoneNumbers.primary eq true', '$phone pr'];
        yield 'type ne of two groups' => ['User', 'phoneNumbers.type ne "work"', 'or(not(or($phone pr, $mobile pr)), $mobile pr)'];
        yield 'value path and' => ['User', 'emails[type eq "work" and value co "@x"]', 'and($email pr, $email co "@x")'];
        yield 'value path Entra form' => ['User', 'emails[type eq "work"].value eq "a@x"', 'and($email pr, $email eq "a@x")'];
        yield 'value path ne per element' => ['User', 'phoneNumbers[value ne "1"]', 'or(and($phone pr, not($phone eq "1")), and($mobile pr, not($mobile eq "1")))'];
        yield 'group of two members' => ['Contact', 'addresses[type eq "work"]', 'or($workLocality pr, $workPostalCode pr)'];
        yield 'sub-attribute of one group' => ['Contact', 'addresses.region eq "NSW"', 'and(or($homeLocality pr, $homeRegion pr), $homeRegion eq "NSW")'];
        yield 'complex pr' => ['User', 'name pr', 'or($firstName pr, $lastName pr, getFormattedName() pr)'];
        yield 'complex value path' => ['User', 'name[givenName eq "Barbara"]', 'and(or($firstName pr, $lastName pr, getFormattedName() pr), $firstName eq "Barbara")'];
        yield 'writeOnly sub-attribute renders nothing' => ['Contact', 'badge pr', '$badgeHolder pr'];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function relations(): iterable
    {
        yield 'reference value' => ['User', 'manager.value eq "'.self::ID.'"', 'exists($manager, $id eq uid:'.self::ID.')'];
        yield 'reference malformed id' => ['User', 'manager eq "x"', 'false'];
        yield 'reference ne' => ['User', 'manager.value ne "'.self::ID.'"', 'not(exists($manager, $id eq uid:'.self::ID.'))'];
        yield 'reference eq null' => ['User', 'manager eq null', 'not(exists($manager))'];
        yield 'untyped collection ne' => ['User', 'groups.value ne "'.self::ID.'"', 'or(not(exists($groups)), exists($groups, not($id eq uid:'.self::ID.')))'];
        yield 'collections value' => ['Group', 'members.value eq "'.self::ID.'"', 'or(exists($users, $id eq uid:'.self::ID.'), exists($subgroups, $id eq uid:'.self::ID.'))'];
        yield 'collections ne malformed id' => ['Group', 'members ne "x"', 'or(not(or(exists($users), exists($subgroups))), or(exists($users), exists($subgroups)))'];
        yield 'collection type' => ['Group', 'members.type eq "Group"', 'exists($subgroups)'];
        yield 'collection type ne' => ['Group', 'members.type ne "User"', 'or(not(or(exists($users), exists($subgroups))), exists($subgroups))'];
        yield 'collection value path and' => ['Group', 'members[type eq "User" and value eq "'.self::ID.'"]', 'exists($users, $id eq uid:'.self::ID.')'];
        yield 'Entra membership check' => ['Group', 'id eq "'.self::ID.'" and members eq "'.self::ID.'"', 'and($id eq uid:'.self::ID.', or(exists($users, $id eq uid:'.self::ID.'), exists($subgroups, $id eq uid:'.self::ID.')))'];
        yield 'typed beside untyped collection' => ['Contact', 'groups.type eq "direct"', 'exists($directGroups)'];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function logic(): iterable
    {
        yield 'nested' => ['User', '(userName eq "a" or userName eq "b") and not (active eq false)', 'and(or($userName eq "a", $userName eq "b"), not($enabled eq false))'];
        yield 'and with false' => ['User', 'userName eq "a" and id eq "x"', 'false'];
        yield 'or with false' => ['User', 'userName eq "a" or id eq "x"', '$userName eq "a"'];
        yield 'or with true' => ['User', 'userName eq "a" or id ne "x"', 'true'];
        yield 'not false' => ['User', 'not (id eq "x")', 'true'];
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
