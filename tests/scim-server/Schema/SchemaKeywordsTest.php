<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\Returned;
use TacticMedia\ScimServer\Schema\Uniqueness;

final class SchemaKeywordsTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{AttributeType|Mutability|Returned|Uniqueness, string}>
     */
    public static function rfc7643Keywords(): iterable
    {
        yield 'type string' => [AttributeType::String, 'string'];
        yield 'type boolean' => [AttributeType::Boolean, 'boolean'];
        yield 'type decimal' => [AttributeType::Decimal, 'decimal'];
        yield 'type integer' => [AttributeType::Integer, 'integer'];
        yield 'type dateTime' => [AttributeType::DateTime, 'dateTime'];
        yield 'type binary' => [AttributeType::Binary, 'binary'];
        yield 'type reference' => [AttributeType::Reference, 'reference'];
        yield 'type complex' => [AttributeType::Complex, 'complex'];
        yield 'mutability readOnly' => [Mutability::ReadOnly, 'readOnly'];
        yield 'mutability readWrite' => [Mutability::ReadWrite, 'readWrite'];
        yield 'mutability immutable' => [Mutability::Immutable, 'immutable'];
        yield 'mutability writeOnly' => [Mutability::WriteOnly, 'writeOnly'];
        yield 'returned always' => [Returned::Always, 'always'];
        yield 'returned never' => [Returned::Never, 'never'];
        yield 'returned default' => [Returned::Default, 'default'];
        yield 'returned request' => [Returned::Request, 'request'];
        yield 'uniqueness none' => [Uniqueness::None, 'none'];
        yield 'uniqueness server' => [Uniqueness::Server, 'server'];
        yield 'uniqueness global' => [Uniqueness::Global, 'global'];
    }

    #[Test]
    #[DataProvider('rfc7643Keywords')]
    #[TestDox('The case value equals the RFC 7643 keyword $keyword')]
    public function theCaseValueEqualsTheRfcKeyword(AttributeType|Mutability|Returned|Uniqueness $case, string $keyword): void
    {
        self::assertSame($keyword, $case->value);
    }
}
