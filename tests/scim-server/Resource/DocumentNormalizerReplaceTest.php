<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Resource;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Resource\DocumentNormalizer;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Tests\Resource\Fixtures\ScimExceptionAssertions;
use TacticMedia\ScimServer\Tests\Resource\Fixtures\TestSchemas;

final class DocumentNormalizerReplaceTest extends KernelTestCase
{
    use ScimExceptionAssertions;
    private const string USER = TestSchemas::USER;
    private const string ENTERPRISE = TestSchemas::ENTERPRISE;
    private const string CUSTOM = TestSchemas::CUSTOM;
    private const array EXISTING = [
        'schemas' => [self::USER, self::ENTERPRISE, self::CUSTOM],
        'id' => '2819c223',
        'externalId' => 'b1',
        'meta' => ['resourceType' => 'User', 'created' => '2011-08-01T21:32:44Z'],
        'userName' => 'bjensen',
        'displayName' => 'Babs',
        'groups' => [['value' => 'g1', 'display' => 'Admins']],
        'storageOnly' => 'x',
        self::ENTERPRISE => ['employeeNumber' => '701984', 'costCenter' => '4130'],
        self::CUSTOM => ['tag' => 't1', 'origin' => ['system' => 'hr', 'key' => 'k1']],
    ];

    #[Test]
    #[TestDox('Keeps stored readOnly and immutable values, clears omitted readWrite ones and drops unknown keys')]
    public function keepsStoredReadOnlyAndImmutableValues(): void
    {
        self::assertSame([
            'schemas' => [self::USER, self::ENTERPRISE, self::CUSTOM],
            'id' => '2819c223',
            'meta' => ['resourceType' => 'User', 'created' => '2011-08-01T21:32:44Z'],
            'userName' => 'bjensen',
            'groups' => [['value' => 'g1', 'display' => 'Admins']],
            self::ENTERPRISE => ['employeeNumber' => '701984', 'costCenter' => '4130'],
            self::CUSTOM => ['tag' => 't1', 'origin' => ['system' => 'hr', 'key' => 'k1']],
        ], self::replace([
            'id' => 'other',
            'meta' => ['resourceType' => 'Group'],
            'groups' => [],
            'userName' => 'bjensen',
            self::ENTERPRISE => ['costCenter' => '4130', 'employeeNumber' => null],
            self::CUSTOM => ['tag' => 't1'],
        ], self::EXISTING));
    }

    #[Test]
    #[TestDox('Accepts immutable values equal to the stored ones in any key order')]
    public function acceptsUnchangedImmutableValues(): void
    {
        self::assertSame([
            'schemas' => [self::USER, self::ENTERPRISE, self::CUSTOM],
            'id' => '2819c223',
            'meta' => ['resourceType' => 'User', 'created' => '2011-08-01T21:32:44Z'],
            'userName' => 'bjensen',
            'groups' => [['value' => 'g1', 'display' => 'Admins']],
            self::ENTERPRISE => ['employeeNumber' => '701984', 'costCenter' => '4130'],
            self::CUSTOM => ['tag' => 't1', 'origin' => ['system' => 'hr', 'key' => 'k1']],
        ], self::replace([
            'userName' => 'bjensen',
            self::ENTERPRISE => ['employeeNumber' => '701984', 'costCenter' => '4130'],
            self::CUSTOM => ['tag' => 't1', 'origin' => ['key' => 'k1', 'system' => 'hr']],
        ], self::EXISTING));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function changedImmutableValues(): iterable
    {
        yield 'simple attribute' => [[self::ENTERPRISE => ['costCenter' => '4130', 'employeeNumber' => '1']], self::ENTERPRISE.':employeeNumber'];
        yield 'complex attribute' => [[self::CUSTOM => ['tag' => 't1', 'origin' => ['system' => 'hr', 'key' => 'k2']]], self::CUSTOM.':origin'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('changedImmutableValues')]
    #[TestDox('Rejects a changed immutable value with mutability')]
    public function rejectsAChangedImmutableValue(array $input, string $path): void
    {
        self::assertScimException(
            ScimErrorType::Mutability,
            \sprintf('Attribute "%s" is immutable.', $path),
            static fn (): array => self::replace(['userName' => 'bjensen', ...$input], self::EXISTING),
        );
    }

    #[Test]
    #[TestDox('Applies an immutable value that has no stored value')]
    public function appliesAnImmutableValueWithoutStoredValue(): void
    {
        self::assertSame(
            ['schemas' => [self::USER, self::ENTERPRISE], 'id' => '2819c223', 'userName' => 'bjensen', self::ENTERPRISE => ['employeeNumber' => '5', 'costCenter' => '1']],
            self::replace(
                ['userName' => 'bjensen', self::ENTERPRISE => ['employeeNumber' => '5', 'costCenter' => '1']],
                ['id' => '2819c223', 'userName' => 'bjensen', self::ENTERPRISE => 'not an object'],
            ),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, array<string, mixed>}>
     */
    public static function storedImmutableValues(): iterable
    {
        yield 'readOnly sub-attribute' => [['c' => ['a' => 'x', 'label' => 'L']], ['c' => ['a' => 'x', 'label' => 'L']], ['c' => ['a' => 'x', 'label' => 'L']]];
        yield 'null sub-attribute' => [['c' => ['a' => 'x', 'label' => null]], ['c' => ['a' => 'x']], ['c' => ['a' => 'x', 'label' => null]]];
        yield 'integer for a decimal' => [['amount' => 5], ['amount' => 5], ['amount' => 5]];
        yield '[] as unassigned' => [['tags' => []], ['tags' => ['a']], ['tags' => ['a']]];
    }

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('storedImmutableValues')]
    #[TestDox('Compares an immutable input with the stored value normalized in the same way')]
    public function comparesWithTheNormalizedStoredValue(array $stored, array $input, array $expected): void
    {
        $type = new ResourceType('Thing', '/Things', new Schema('urn:example:Thing', 'Thing', '', [
            new AttributeDefinition('c', AttributeType::Complex, mutability: Mutability::Immutable, subAttributes: [
                new AttributeDefinition('a'),
                new AttributeDefinition('label', mutability: Mutability::ReadOnly),
            ]),
            new AttributeDefinition('tags', multiValued: true, mutability: Mutability::Immutable),
            new AttributeDefinition('amount', AttributeType::Decimal, mutability: Mutability::Immutable),
        ]));

        self::assertSame(
            ['schemas' => ['urn:example:Thing'], 'id' => '1', ...$expected],
            new DocumentNormalizer()->forReplace($input, ['id' => '1', ...$stored], $type),
        );
    }

    #[Test]
    #[TestDox('Rejects a missing required attribute with invalidValue')]
    public function rejectsAMissingRequiredAttribute(): void
    {
        self::assertScimException(
            ScimErrorType::InvalidValue,
            'Attribute "userName" is required.',
            static fn (): array => self::replace(['displayName' => 'Babs'], self::EXISTING),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing
     *
     * @return array<string, mixed>
     */
    private static function replace(array $input, array $existing): array
    {
        return new DocumentNormalizer()->forReplace($input, $existing, TestSchemas::userType());
    }
}
