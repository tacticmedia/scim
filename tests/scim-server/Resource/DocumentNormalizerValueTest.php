<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Resource;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Resource\DocumentNormalizer;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Tests\Resource\Fixtures\ScimExceptionAssertions;
use TacticMedia\ScimServer\Tests\Resource\Fixtures\TestSchemas;

final class DocumentNormalizerValueTest extends KernelTestCase
{
    use ScimExceptionAssertions;
    private const string USER = TestSchemas::USER;
    private const string ENTERPRISE = TestSchemas::ENTERPRISE;

    /**
     * @return iterable<string, array{string, mixed, mixed}>
     */
    public static function values(): iterable
    {
        yield 'single object for a multi-valued attribute' => ['emails', ['VALUE' => 'b@example.com'], [['value' => 'b@example.com']]];
        yield 'list for a multi-valued attribute' => ['emails', [['value' => 'a'], ['value' => 'b']], [['value' => 'a'], ['value' => 'b']]];
        yield 'scalar for a multi-valued attribute' => ['roles', 'admin', ['admin']];
        yield 'bare value for a multi-valued complex attribute' => ['emails', 'b@example.com', [['value' => 'b@example.com']]];
        yield 'second primary value' => [
            'emails',
            [['value' => 'a', 'primary' => true], ['value' => 'b', 'primary' => 'True']],
            [['value' => 'a', 'primary' => true], ['value' => 'b', 'primary' => false]],
        ];
        yield 'required sub-attribute absent' => ['emails', ['type' => 'work'], [['type' => 'work']]];
        yield 'empty list' => ['emails', [], null];
        yield 'null for a multi-valued attribute' => ['roles', null, null];
        yield 'boolean string for a sub-attribute' => ['emails.primary', 'True', true];
        yield 'sub-attribute of a complex attribute' => ['name.givenName', 'Barbara', 'Barbara'];
        yield 'bare id for manager' => [self::ENTERPRISE.':manager', 'm1', ['value' => 'm1']];
        yield 'empty string for manager' => [self::ENTERPRISE.':manager', '', null];
        yield 'list with one object for manager' => [self::ENTERPRISE.':manager', [['value' => 'm1']], ['value' => 'm1']];
        yield 'readOnly sub-attribute of manager' => [self::ENTERPRISE.':manager', ['value' => 'm1', 'displayName' => 'Boss'], ['value' => 'm1']];
        yield 'manager sub-attribute' => [self::ENTERPRISE.':manager.value', 'm1', 'm1'];
    }

    #[Test]
    #[DataProvider('values')]
    #[TestDox('Normalizes a PATCH value for an attribute or a sub-attribute')]
    public function normalizesAPatchValue(string $path, mixed $value, mixed $expected): void
    {
        self::assertSame($expected, new DocumentNormalizer()->normalizeValue($value, self::resolve($path)));
    }

    /**
     * @return iterable<string, array{string, mixed, ScimErrorType, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'wrong type' => ['active', 'maybe', ScimErrorType::InvalidValue, 'Attribute "'.self::USER.':active" must be a boolean.'];
        yield 'list for a single-valued sub-attribute' => ['name.givenName', ['a'], ScimErrorType::InvalidValue, 'Attribute "'.self::USER.':name.givenName" must be a string.'];
        yield 'sub-attribute given twice' => [
            'emails',
            [['value' => 'a', 'VALUE' => 'b']],
            ScimErrorType::InvalidSyntax,
            'Attribute "'.self::USER.':emails.value" appears more than once.',
        ];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    #[TestDox('Rejects an invalid PATCH value with the qualified attribute path')]
    public function rejectsAnInvalidPatchValue(string $path, mixed $value, ScimErrorType $scimType, string $detail): void
    {
        self::assertScimException($scimType, $detail, static fn (): mixed => new DocumentNormalizer()->normalizeValue($value, self::resolve($path)));
    }

    private static function resolve(string $path): ResolvedAttribute
    {
        $attributePath = AttributePath::parse($path);
        self::assertNotNull($attributePath);
        $attribute = TestSchemas::userType()->resolve($attributePath);
        self::assertNotNull($attribute);

        return $attribute;
    }
}
