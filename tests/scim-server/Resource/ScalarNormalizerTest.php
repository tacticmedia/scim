<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Resource;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Resource\ScalarNormalizer;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Tests\Resource\Fixtures\ScimExceptionAssertions;

final class ScalarNormalizerTest extends KernelTestCase
{
    use ScimExceptionAssertions;

    /**
     * @return iterable<string, array{AttributeType, mixed, string|bool|int|float}>
     */
    public static function validValues(): iterable
    {
        yield 'string with case and spaces' => [AttributeType::String, ' BJensen ', ' BJensen '];
        yield 'empty string' => [AttributeType::String, '', ''];
        yield 'reference' => [AttributeType::Reference, '../Users/2819c223', '../Users/2819c223'];
        yield 'boolean false' => [AttributeType::Boolean, false, false];
        yield 'boolean string True' => [AttributeType::Boolean, 'True', true];
        yield 'boolean string FALSE' => [AttributeType::Boolean, 'FALSE', false];
        yield 'integer' => [AttributeType::Integer, -42, -42];
        yield 'decimal' => [AttributeType::Decimal, 1.5, 1.5];
        yield 'decimal from integer' => [AttributeType::Decimal, 10, 10.0];
        yield 'dateTime in UTC' => [AttributeType::DateTime, '2008-01-23T04:56:22Z', '2008-01-23T04:56:22Z'];
        yield 'dateTime with fraction and offset' => [AttributeType::DateTime, '2011-05-13T04:42:34.123456789+10:00', '2011-05-13T04:42:34.123456789+10:00'];
        yield 'dateTime on a leap day at offset -14:00' => [AttributeType::DateTime, '2024-02-29T23:59:59-14:00', '2024-02-29T23:59:59-14:00'];
        yield 'binary with padding' => [AttributeType::Binary, 'YQ==', 'YQ=='];
        yield 'binary without padding' => [AttributeType::Binary, 'YQ', 'YQ'];
    }

    #[Test]
    #[DataProvider('validValues')]
    #[TestDox('Accepts a valid value of each type')]
    public function acceptsAValidValueOfEachType(AttributeType $type, mixed $value, string|bool|int|float $expected): void
    {
        self::assertSame($expected, new ScalarNormalizer()->normalize($value, $type, 'attribute'));
    }

    /**
     * @return iterable<string, array{AttributeType, mixed, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'string from integer' => [AttributeType::String, 5, 'a string'];
        yield 'reference from list' => [AttributeType::Reference, ['../Users/1'], 'a reference string'];
        yield 'boolean from yes' => [AttributeType::Boolean, 'yes', 'a boolean'];
        yield 'boolean from integer' => [AttributeType::Boolean, 1, 'a boolean'];
        yield 'integer from float' => [AttributeType::Integer, 1.0, 'an integer'];
        yield 'integer from numeric string' => [AttributeType::Integer, '1', 'an integer'];
        yield 'decimal from numeric string' => [AttributeType::Decimal, '1.5', 'a number'];
        yield 'decimal infinity' => [AttributeType::Decimal, INF, 'a number'];
        yield 'decimal negative infinity' => [AttributeType::Decimal, -INF, 'a number'];
        yield 'decimal NaN' => [AttributeType::Decimal, NAN, 'a number'];
        yield 'dateTime without timezone' => [AttributeType::DateTime, '2008-01-23T04:56:22', 'an xsd:dateTime with a timezone'];
        yield 'dateTime without time' => [AttributeType::DateTime, '2008-01-23', 'an xsd:dateTime with a timezone'];
        yield 'dateTime on a missing day' => [AttributeType::DateTime, '2023-02-29T00:00:00Z', 'an xsd:dateTime with a timezone'];
        yield 'dateTime in month 13' => [AttributeType::DateTime, '2023-13-01T00:00:00Z', 'an xsd:dateTime with a timezone'];
        yield 'dateTime at 24:00:00' => [AttributeType::DateTime, '2024-02-29T24:00:00-14:00', 'an xsd:dateTime with a timezone'];
        yield 'dateTime with a five-digit year' => [AttributeType::DateTime, '10000-01-01T00:00:00Z', 'an xsd:dateTime with a timezone'];
        yield 'dateTime at offset +14:01' => [AttributeType::DateTime, '2024-01-01T00:00:00+14:01', 'an xsd:dateTime with a timezone'];
        yield 'dateTime from integer' => [AttributeType::DateTime, 1700000000, 'an xsd:dateTime with a timezone'];
        yield 'binary with whitespace' => [AttributeType::Binary, 'YW Jj', 'base64 encoded'];
        yield 'binary with bad padding' => [AttributeType::Binary, 'YQ=', 'base64 encoded'];
        yield 'binary in base64url' => [AttributeType::Binary, 'a-b_', 'base64 encoded'];
        yield 'binary from integer' => [AttributeType::Binary, 5, 'base64 encoded'];
        yield 'complex' => [AttributeType::Complex, 'x', 'a complex object'];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    #[TestDox('Rejects an invalid value with invalidValue and the attribute path')]
    public function rejectsAnInvalidValue(AttributeType $type, mixed $value, string $expectation): void
    {
        self::assertScimException(
            ScimErrorType::InvalidValue,
            \sprintf('Attribute "emails.value" must be %s.', $expectation),
            static fn (): mixed => new ScalarNormalizer()->normalize($value, $type, 'emails.value'),
        );
    }
}
