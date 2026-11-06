<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\Schema;

final class ResolvedAttributeTest extends KernelTestCase
{
    #[Test]
    #[TestDox('definition() is the sub-attribute when there is one')]
    public function definitionIsTheSubAttributeWhenThereIsOne(): void
    {
        $givenName = new AttributeDefinition('givenName');
        $name = new AttributeDefinition('name', AttributeType::Complex, subAttributes: [$givenName]);
        $schema = new Schema('urn:example:User', 'User', '', [$name]);

        self::assertSame($givenName, new ResolvedAttribute($schema, $name, $givenName)->definition());
        self::assertSame($name, new ResolvedAttribute($schema, $name)->definition());
    }

    #[Test]
    #[TestDox('path() is qualified with the schema URN in schema spelling')]
    public function pathIsQualifiedWithTheSchemaUrnInSchemaSpelling(): void
    {
        $givenName = new AttributeDefinition('givenName');
        $name = new AttributeDefinition('name', AttributeType::Complex, subAttributes: [$givenName]);
        $schema = new Schema('urn:example:User', 'User', '', [$name]);

        self::assertSame('urn:example:User:name.givenName', (string) new ResolvedAttribute($schema, $name, $givenName)->path());
        self::assertSame('urn:example:User:name', (string) new ResolvedAttribute($schema, $name)->path());
    }
}
