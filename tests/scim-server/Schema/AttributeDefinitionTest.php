<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\Returned;
use TacticMedia\ScimServer\Schema\Uniqueness;

final class AttributeDefinitionTest extends KernelTestCase
{
    #[Test]
    #[TestDox('subAttribute() matches the name in any case')]
    public function subAttributeMatchesTheNameInAnyCase(): void
    {
        $givenName = new AttributeDefinition('givenName');
        $name = new AttributeDefinition('name', AttributeType::Complex, subAttributes: [new AttributeDefinition('familyName'), $givenName]);

        self::assertSame($givenName, $name->subAttribute('GIVENNAME'));
    }

    #[Test]
    #[TestDox('subAttribute() returns null for an unknown name')]
    public function subAttributeReturnsNullForAnUnknownName(): void
    {
        $name = new AttributeDefinition('name', AttributeType::Complex, subAttributes: [new AttributeDefinition('givenName')]);

        self::assertNull($name->subAttribute('nickName'));
    }

    #[Test]
    #[TestDox('toArray() of a simple attribute has the nine characteristics only')]
    public function toArrayOfASimpleAttributeHasTheNineCharacteristicsOnly(): void
    {
        $password = new AttributeDefinition('password', description: 'Cleartext.', required: true, caseExact: true, mutability: Mutability::WriteOnly, returned: Returned::Never, uniqueness: Uniqueness::Global);

        self::assertSame([
            'name' => 'password',
            'type' => 'string',
            'multiValued' => false,
            'description' => 'Cleartext.',
            'required' => true,
            'caseExact' => true,
            'mutability' => 'writeOnly',
            'returned' => 'never',
            'uniqueness' => 'global',
        ], $password->toArray());
    }

    #[Test]
    #[TestDox('A writeOnly attribute is returned "never", whatever "returned" says')]
    public function aWriteOnlyAttributeIsReturnedNever(): void
    {
        $code = new AttributeDefinition('code', mutability: Mutability::WriteOnly, returned: Returned::Request);

        self::assertSame([Returned::Never, 'never'], [$code->returned, $code->toArray()['returned']]);
    }

    #[Test]
    #[TestDox('toArray() of a reference always has referenceTypes')]
    public function toArrayOfAReferenceAlwaysHasReferenceTypes(): void
    {
        $reference = new AttributeDefinition('$ref', AttributeType::Reference);

        self::assertSame([], $reference->toArray()['referenceTypes']);
    }
}
