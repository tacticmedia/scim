<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Resource;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Resource\DocumentProjector;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Returned;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Tests\Resource\Fixtures\TestSchemas;

final class DocumentProjectorTest extends KernelTestCase
{
    private const string USER = TestSchemas::USER;
    private const string ENTERPRISE = TestSchemas::ENTERPRISE;
    private const array DOCUMENT = [
        'schemas' => [self::USER, self::ENTERPRISE],
        'id' => '2819c223',
        'externalId' => 'b1',
        'userName' => 'bjensen',
        'name' => ['givenName' => 'Barbara', 'familyName' => 'Jensen'],
        'password' => 'secret',
        'emails' => [['value' => 'b@example.com', 'type' => 'work', 'primary' => true], ['type' => 'home']],
        'loginCount' => 3,
        'badge' => ['code' => 'B1', 'issuer' => 'HR', 'pin' => '1234'],
        'storageOnly' => 'x',
        self::ENTERPRISE => ['employeeNumber' => '701984', 'manager' => ['value' => 'm1', '$ref' => '../Users/m1']],
        'meta' => ['resourceType' => 'User', 'created' => '2011-08-01T21:32:44Z'],
    ];
    private const array HEAD = ['schemas' => [self::USER, self::ENTERPRISE], 'id' => '2819c223'];
    private const array BADGE = ['badge' => ['code' => 'B1']];
    private const array MINIMUM = [...self::HEAD, ...self::BADGE];
    private const array DEFAULT = [
        'schemas' => [self::USER, self::ENTERPRISE],
        'id' => '2819c223',
        'externalId' => 'b1',
        'userName' => 'bjensen',
        'name' => ['givenName' => 'Barbara', 'familyName' => 'Jensen'],
        'emails' => [['value' => 'b@example.com', 'type' => 'work', 'primary' => true], ['type' => 'home']],
        'badge' => ['code' => 'B1'],
        self::ENTERPRISE => ['employeeNumber' => '701984', 'manager' => ['value' => 'm1', '$ref' => '../Users/m1']],
        'meta' => ['resourceType' => 'User', 'created' => '2011-08-01T21:32:44Z'],
    ];

    /**
     * @return iterable<string, array{list<string>, list<string>, list<string>, array<string, mixed>}>
     */
    public static function projections(): iterable
    {
        yield 'default set' => [[], [], [], self::DEFAULT];
        yield 'attributes replace the default set' => [['userName'], [], [], [...self::HEAD, 'userName' => 'bjensen', ...self::BADGE]];
        yield 'attributes in any case, URN-qualified' => [
            ['USERNAME', self::USER.':Name'],
            [],
            [],
            [...self::HEAD, 'userName' => 'bjensen', 'name' => ['givenName' => 'Barbara', 'familyName' => 'Jensen'], ...self::BADGE],
        ];
        yield 'sub-attributes select only themselves' => [
            ['name.givenName', 'emails.value'],
            [],
            [],
            [...self::HEAD, 'name' => ['givenName' => 'Barbara'], 'emails' => [['value' => 'b@example.com']], ...self::BADGE],
        ];
        yield 'unqualified extension attribute' => [['employeeNumber'], [], [], [...self::MINIMUM, self::ENTERPRISE => ['employeeNumber' => '701984']]];
        yield 'URN-qualified extension sub-attribute' => [
            [self::ENTERPRISE.':manager.value'],
            [],
            [],
            [...self::MINIMUM, self::ENTERPRISE => ['manager' => ['value' => 'm1']]],
        ];
        yield 'meta sub-attribute' => [['meta.created'], [], [], [...self::MINIMUM, 'meta' => ['created' => '2011-08-01T21:32:44Z']]];
        yield 'request attributes named, never attribute named' => [
            ['loginCount', 'password', 'badge.issuer', 'badge.pin'],
            [],
            [],
            [...self::HEAD, 'loginCount' => 3, 'badge' => ['code' => 'B1', 'issuer' => 'HR']],
        ];
        yield 'unknown names leave the minimum set' => [['nickName2', 'urn:example:unknown:2.0:User:tag'], [], [], self::MINIMUM];
        yield 'excluded attributes and sub-attributes' => [
            [],
            ['emails', 'NAME.familyName', 'meta', 'externalId'],
            [],
            [
                'schemas' => [self::USER, self::ENTERPRISE],
                'id' => '2819c223',
                'userName' => 'bjensen',
                'name' => ['givenName' => 'Barbara'],
                'badge' => ['code' => 'B1'],
                self::ENTERPRISE => ['employeeNumber' => '701984', 'manager' => ['value' => 'm1', '$ref' => '../Users/m1']],
            ],
        ];
        yield 'excluding always attributes has no effect' => [[], ['id', 'badge', 'schemas'], [], self::DEFAULT];
        yield 'extension removed when every attribute is excluded' => [
            [],
            [self::ENTERPRISE.':employeeNumber', 'manager'],
            [],
            array_diff_key(self::DEFAULT, [self::ENTERPRISE => true]),
        ];
        yield 'whole extension URN in attributes' => [
            [self::ENTERPRISE],
            [],
            [],
            [...self::MINIMUM, self::ENTERPRISE => ['employeeNumber' => '701984', 'manager' => ['value' => 'm1', '$ref' => '../Users/m1']]],
        ];
        yield 'whole extension URN in excludedAttributes, in any case' => [[], [strtoupper(self::ENTERPRISE)], [], array_diff_key(self::DEFAULT, [self::ENTERPRISE => true])];
        yield 'excluded request attributes that the client sent' => [[], ['loginCount', 'badge.issuer'], ['loginCount', 'badge.issuer'], self::DEFAULT];
        yield 'requested attributes' => [
            [],
            [],
            ['loginCount', 'badge.issuer'],
            [
                'schemas' => [self::USER, self::ENTERPRISE],
                'id' => '2819c223',
                'externalId' => 'b1',
                'userName' => 'bjensen',
                'name' => ['givenName' => 'Barbara', 'familyName' => 'Jensen'],
                'emails' => [['value' => 'b@example.com', 'type' => 'work', 'primary' => true], ['type' => 'home']],
                'loginCount' => 3,
                'badge' => ['code' => 'B1', 'issuer' => 'HR'],
                self::ENTERPRISE => ['employeeNumber' => '701984', 'manager' => ['value' => 'm1', '$ref' => '../Users/m1']],
                'meta' => ['resourceType' => 'User', 'created' => '2011-08-01T21:32:44Z'],
            ],
        ];
    }

    /**
     * @param list<string>         $attributes
     * @param list<string>         $excludedAttributes
     * @param list<string>         $requested
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('projections')]
    #[TestDox('Applies "returned", attributes, excludedAttributes and the requested attributes')]
    public function appliesReturnedAndTheAttributeLists(array $attributes, array $excludedAttributes, array $requested, array $expected): void
    {
        self::assertSame($expected, new DocumentProjector()->project(
            self::DOCUMENT,
            TestSchemas::userType(),
            $this->paths($attributes),
            $this->paths($excludedAttributes),
            $this->paths($requested),
        ));
    }

    #[Test]
    #[TestDox('returnable() keeps every value but those never returned, and drops unknown keys')]
    public function returnableDropsOnlyNeverReturnedValues(): void
    {
        $expected = self::DOCUMENT;
        unset($expected['password'], $expected['badge']['pin'], $expected['storageOnly']);

        self::assertSame($expected, new DocumentProjector()->returnable(self::DOCUMENT, TestSchemas::userType()));
    }

    #[Test]
    #[TestDox('Passes through values of the wrong shape and drops unknown keys')]
    public function passesThroughValuesOfTheWrongShape(): void
    {
        self::assertSame(
            ['schemas' => [self::USER], 'name' => 'Barbara', 'emails' => ['b@example.com']],
            new DocumentProjector()->project(
                ['schemas' => [self::USER], 'name' => 'Barbara', 'emails' => ['b@example.com'], self::ENTERPRISE => 'x', 'urn:example:unknown:2.0:User' => ['tag' => 'x']],
                TestSchemas::userType(),
            ),
        );
    }

    #[Test]
    #[TestDox('Never returns a writeOnly value, whatever "returned" says')]
    public function neverReturnsAWriteOnlyValue(): void
    {
        $type = new ResourceType('Client', '/Clients', new Schema('urn:example:Client', 'Client', '', [
            new AttributeDefinition('name'),
            new AttributeDefinition('secret', mutability: Mutability::WriteOnly),
            new AttributeDefinition('code', mutability: Mutability::WriteOnly, returned: Returned::Request),
            new AttributeDefinition('credentials', AttributeType::Complex, returned: Returned::Always, subAttributes: [
                new AttributeDefinition('user'),
                new AttributeDefinition('key', mutability: Mutability::WriteOnly),
            ]),
        ]));
        $named = $this->paths(['name', 'secret', 'code', 'credentials.key']);

        self::assertSame(
            ['schemas' => ['urn:example:Client'], 'name' => 'c', 'credentials' => ['user' => 'u']],
            new DocumentProjector()->project(
                ['schemas' => ['urn:example:Client'], 'name' => 'c', 'secret' => 's', 'code' => 'c1', 'credentials' => ['user' => 'u', 'key' => 'k']],
                $type,
                $named,
                [],
                $named,
            ),
        );
    }

    /**
     * @param list<string> $paths
     *
     * @return list<AttributePath>
     */
    private function paths(array $paths): array
    {
        return array_map(static fn (string $path): AttributePath => AttributePath::parse($path) ?? self::fail('Invalid path '.$path), $paths);
    }
}
