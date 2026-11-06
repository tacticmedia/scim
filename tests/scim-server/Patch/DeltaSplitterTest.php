<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Patch;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Patch\DeltaSplit;
use TacticMedia\ScimServer\Patch\DeltaSplitter;
use TacticMedia\ScimServer\Patch\PatchOperation;
use TacticMedia\ScimServer\Patch\PatchOperationType;
use TacticMedia\ScimServer\Patch\PatchRequest;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Repository\ValuesStep;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Schema\CoreSchemas;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Schema\SchemaExtension;

final class DeltaSplitterTest extends KernelTestCase
{
    private const string GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    private const string TEAM = 'urn:example:scim:schemas:extension:team:2.0:Group';

    /**
     * @return iterable<string, array{list<array<string, mixed>>, list<ValuesStep>}>
     */
    public static function memberOperations(): iterable
    {
        yield 'Entra add with "$ref": null' => [[['op' => 'Add', 'path' => 'members', 'value' => [['$ref' => null, 'value' => 'm1']]]], [self::step('add', [['value' => 'm1']])]];
        yield 'RFC 7644 add with display and $ref' => [
            [['op' => 'add', 'path' => 'members', 'value' => [['display' => 'Babs', '$ref' => 'https://example.com/v2/Users/m1', 'value' => 'm1']]]],
            [self::step('add', [['$ref' => 'https://example.com/v2/Users/m1', 'value' => 'm1']])],
        ];
        yield 'add of a bare id' => [[['op' => 'add', 'path' => 'members', 'value' => 'm1']], [self::step('add', [['value' => 'm1']])]];
        yield 'add with a URN-qualified path in other case' => [[['op' => 'add', 'path' => self::GROUP.':Members', 'value' => [['value' => 'm1'], ['value' => 'm2']]]], [self::step('add', [['value' => 'm1'], ['value' => 'm2']])]];
        yield 'Entra remove with a value list' => [[['op' => 'Remove', 'path' => 'members', 'value' => [['value' => 'm1']]]], [self::step('remove', [['value' => 'm1']])]];
        yield 'Entra flag-style remove filter' => [[['op' => 'Remove', 'path' => 'members[value eq "m1"]']], [self::step('remove', [['value' => 'm1']])]];
        yield 'Okta remove filter plus add' => [
            [['op' => 'remove', 'path' => 'members[value eq "m2"]'], ['op' => 'add', 'path' => 'members', 'value' => [['value' => 'm1', 'display' => 'Babs']]]],
            [self::step('remove', [['value' => 'm2']]), self::step('add', [['value' => 'm1']])],
        ];
        yield 'Okta replace' => [[['op' => 'replace', 'path' => 'members', 'value' => [['value' => 'm1', 'display' => 'Babs']]]], [self::step('replace', [['value' => 'm1']])]];
        yield 'remove without value' => [[['op' => 'remove', 'path' => 'members']], [self::step('replace', [])]];
        yield 'replace with []' => [[['op' => 'replace', 'path' => 'members', 'value' => []]], [self::step('replace', [])]];
        yield 'replace with ""' => [[['op' => 'replace', 'path' => 'members', 'value' => '']], [self::step('replace', [])]];
        yield 'replace with null' => [[['op' => 'replace', 'path' => 'members', 'value' => null]], [self::step('replace', [])]];
        yield 'add with []' => [[['op' => 'add', 'path' => 'members', 'value' => []]], []];
        yield 'remove with []' => [[['op' => 'remove', 'path' => 'members', 'value' => []]], []];
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @param list<ValuesStep>           $steps
     */
    #[Test]
    #[DataProvider('memberOperations')]
    #[TestDox('$_dataName becomes steps')]
    public function splitsMemberOperations(array $operations, array $steps): void
    {
        self::assertEquals(new DeltaSplit([], $steps, [self::members()]), $this->split($operations));
    }

    #[Test]
    #[TestDox('Operations on other attributes stay, keyed by their index in the request')]
    public function keepsOtherOperations(): void
    {
        $split = $this->split([
            ['op' => 'replace', 'value' => ['id' => 'g1', 'displayName' => 'Tour Guides']],
            ['op' => 'add', 'path' => 'members', 'value' => [['value' => 'm1']]],
            ['op' => 'replace', 'path' => 'externalId', 'value' => 'e1'],
        ]);

        self::assertEquals(new DeltaSplit(
            [0 => new PatchOperation(PatchOperationType::Replace, null, ['id' => 'g1', 'displayName' => 'Tour Guides']), 2 => new PatchOperation(PatchOperationType::Replace, 'externalId', 'e1')],
            [self::step('add', [['value' => 'm1']])],
            [self::members()],
        ), $split);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function fullPathOperations(): iterable
    {
        yield 'a path-less add with a members key' => [['op' => 'add', 'value' => ['members' => [['value' => 'm1']]]]];
        yield 'a filter and a sub-attribute' => [['op' => 'remove', 'path' => 'members[value eq "m1"].type']];
        yield 'a filter on type' => [['op' => 'remove', 'path' => 'members[type eq "User"]']];
        yield 'a filter with or' => [['op' => 'remove', 'path' => 'members[value eq "m1" or value eq "m2"]']];
        yield 'a filter with ne' => [['op' => 'remove', 'path' => 'members[value ne "m1"]']];
        yield 'add with a filter' => [['op' => 'add', 'path' => 'members[value eq "m1"]', 'value' => ['type' => 'User']]];
        yield 'an element without value' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => 'm1'], ['type' => 'User']]]];
        yield 'an element that fails normalization' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => 42]]]];
        yield 'a path that does not resolve' => [['op' => 'replace', 'path' => 'shoeSize', 'value' => 42]];
    }

    /**
     * @param array<string, mixed> $operation
     */
    #[Test]
    #[DataProvider('fullPathOperations')]
    #[TestDox('The full path applies a request with $_dataName')]
    public function leavesRequestToFullPath(array $operation): void
    {
        self::assertNull($this->split([['op' => 'add', 'path' => 'members', 'value' => 'm0'], $operation]));
    }

    #[Test]
    #[TestDox('A repository without delta attributes leaves the request to the full path')]
    public function needsDeltaAttributes(): void
    {
        self::assertNull($this->split([['op' => 'replace', 'path' => 'displayName', 'value' => 'x']], []));
    }

    /**
     * @return iterable<string, array{ResourceType, AttributePath, string}>
     */
    public static function invalidDeltaAttributes(): iterable
    {
        yield 'an unknown attribute' => [CoreResourceTypes::group(), new AttributePath(self::GROUP, 'owners'), self::GROUP.':owners'];
        yield 'a single-valued attribute' => [CoreResourceTypes::group(), new AttributePath(self::GROUP, 'displayName'), self::GROUP.':displayName'];
        yield 'a sub-attribute' => [CoreResourceTypes::group(), new AttributePath(self::GROUP, 'members', 'value'), self::GROUP.':members.value'];
        yield 'a readOnly attribute' => [CoreResourceTypes::user(), new AttributePath(null, 'groups'), 'groups'];
        yield 'an attribute with primary' => [CoreResourceTypes::user(), new AttributePath(null, 'emails'), 'emails'];
        yield 'a simple multi-valued attribute' => [self::teamType(), new AttributePath(self::TEAM, 'tags'), self::TEAM.':tags'];
        yield 'a required attribute' => [self::teamType(), new AttributePath(self::TEAM, 'leads'), self::TEAM.':leads'];
        yield 'a complex attribute without value' => [self::teamType(), new AttributePath(self::TEAM, 'rooms'), self::TEAM.':rooms'];
    }

    #[Test]
    #[DataProvider('invalidDeltaAttributes')]
    #[TestDox('A delta attribute that is $_dataName is a LogicException')]
    public function rejectsInvalidDeltaAttribute(ResourceType $type, AttributePath $path, string $named): void
    {
        $this->expectExceptionObject(new \LogicException(\sprintf('The DeltaRepository of the resource type %s names "%s", which is not a multi-valued, complex, readWrite and optional attribute with a "value" and without a "primary" sub-attribute.', $type->name, $named)));

        new DeltaSplitter()->split(new PatchRequest([new PatchOperation(PatchOperationType::Remove, 'externalId')]), $type, [$path]);
    }

    #[Test]
    #[TestDox('An extension attribute gives steps with its URN-qualified path; a path of the extension URN takes the full path')]
    public function splitsExtensionAttribute(): void
    {
        $owners = new AttributePath(self::TEAM, 'owners');
        $splitter = new DeltaSplitter();
        $request = static fn (PatchOperation $operation): PatchRequest => new PatchRequest([$operation]);

        self::assertEquals(
            new DeltaSplit([], [new ValuesStep($owners, PatchOperationType::Add, [['value' => 'u1']])], [$owners]),
            $splitter->split($request(new PatchOperation(PatchOperationType::Add, self::TEAM.':owners', 'u1')), self::teamType(), [$owners]),
        );
        self::assertNull($splitter->split($request(new PatchOperation(PatchOperationType::Add, self::TEAM, ['owners' => 'u1'])), self::teamType(), [$owners]));
        self::assertNull($splitter->split($request(new PatchOperation(PatchOperationType::Remove, self::TEAM)), self::teamType(), [$owners]));
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @param list<AttributePath>|null   $deltaAttributes null: Group members
     */
    private function split(array $operations, ?array $deltaAttributes = null): ?DeltaSplit
    {
        $request = PatchRequest::fromArray(['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => $operations]);

        return new DeltaSplitter()->split($request, CoreResourceTypes::group(), $deltaAttributes ?? [self::members()]);
    }

    private static function members(): AttributePath
    {
        return new AttributePath(self::GROUP, 'members');
    }

    /**
     * @param list<array<string, mixed>> $values
     */
    private static function step(string $type, array $values): ValuesStep
    {
        return new ValuesStep(self::members(), PatchOperationType::from($type), $values);
    }

    private static function teamType(): ResourceType
    {
        $reference = [new AttributeDefinition('value'), new AttributeDefinition('display')];

        return new ResourceType('Group', '/Groups', CoreSchemas::group(), [new SchemaExtension(new Schema(self::TEAM, 'Team', 'Team', [
            new AttributeDefinition('owners', AttributeType::Complex, multiValued: true, subAttributes: $reference),
            new AttributeDefinition('tags', multiValued: true),
            new AttributeDefinition('leads', AttributeType::Complex, multiValued: true, required: true, subAttributes: $reference),
            new AttributeDefinition('rooms', AttributeType::Complex, multiValued: true, subAttributes: [new AttributeDefinition('name')]),
        ]))]);
    }
}
