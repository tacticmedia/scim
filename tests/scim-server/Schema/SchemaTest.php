<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\Schema;

final class SchemaTest extends KernelTestCase
{
    #[Test]
    #[TestDox('attribute() matches the name in any case')]
    public function attributeMatchesTheNameInAnyCase(): void
    {
        $userName = new AttributeDefinition('userName');
        $schema = new Schema('urn:example:User', 'User', 'User Account', [new AttributeDefinition('nickName'), $userName]);

        self::assertSame($userName, $schema->attribute('USERNAME'));
    }

    #[Test]
    #[TestDox('attribute() returns null for an unknown name')]
    public function attributeReturnsNullForAnUnknownName(): void
    {
        $schema = new Schema('urn:example:User', 'User', 'User Account', [new AttributeDefinition('userName')]);

        self::assertNull($schema->attribute('displayName'));
    }

    #[Test]
    #[TestDox('toArray() has the id, name, description and serialized attributes')]
    public function toArrayHasTheIdNameDescriptionAndSerializedAttributes(): void
    {
        $userName = new AttributeDefinition('userName');
        $schema = new Schema('urn:example:User', 'User', 'User Account', [$userName]);

        self::assertSame([
            'id' => 'urn:example:User',
            'name' => 'User',
            'description' => 'User Account',
            'attributes' => [$userName->toArray()],
        ], $schema->toArray());
    }
}
