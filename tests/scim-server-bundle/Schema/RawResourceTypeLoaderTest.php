<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\Schema\RawResourceTypeLoader;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\RawCustomTypeWithoutSchema;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\RawInvalidEndpoint;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\RawInvalidPath;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\RawNotRepository;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\RawReservedEndpoint;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\RawUnknownAttribute;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\RawUnknownSchema;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\RawValueFilter;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Repository\PrinterRepository;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Repository\RepositoryDecorator;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\DirectoryGroupRepository;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\LdapUserRepository;

final class RawResourceTypeLoaderTest extends KernelTestCase
{
    #[Test]
    #[TestDox('An RFC User repository publishes its declared attributes, slot types and enterprise attributes')]
    public function rfcUserPublishesTheDeclaredAttributes(): void
    {
        $type = new RawResourceTypeLoader()->load(LdapUserRepository::class);

        self::assertSame(['User', '/Users'], [$type->name, $type->endpoint]);
        self::assertSame(['userName', 'name', 'emails', 'groups'], self::names($type->schema));
        self::assertSame(['givenName'], self::names($type->schema->attribute('name')));
        self::assertSame(['value', 'type', 'primary'], self::names($type->schema->attribute('emails')));
        self::assertSame(['work'], $type->schema->attribute('emails')?->subAttribute('type')?->canonicalValues);
        self::assertSame([['urn:ietf:params:scim:schemas:extension:enterprise:2.0:User', ['department']]], array_map(static fn (\TacticMedia\ScimServer\Schema\SchemaExtension $extension): array => [$extension->schema->id, self::names($extension->schema)], $type->schemaExtensions));
    }

    #[Test]
    #[TestDox('An RFC Group repository publishes a whole element with every sub-attribute and the type literals')]
    public function rfcGroupPublishesTheTypedMembers(): void
    {
        $type = new RawResourceTypeLoader()->load(DirectoryGroupRepository::class);
        $members = $type->schema->attribute('members');

        self::assertSame(['Group', '/Teams', ['displayName', 'members']], [$type->name, $type->endpoint, self::names($type->schema)]);
        self::assertSame(['value', '$ref', 'display', 'type'], self::names($members));
        self::assertSame(['User'], $members?->subAttribute('type')?->canonicalValues);
    }

    #[Test]
    #[TestDox('An application core schema and extension come from schemas:, with only the declared attributes')]
    public function applicationSchemasPublishTheDeclaredAttributes(): void
    {
        $type = new RawResourceTypeLoader()->load(PrinterRepository::class);

        self::assertSame(['Printer', '/Printers', 'Printers'], [$type->name, $type->endpoint, $type->description]);
        self::assertSame(['urn:acme:scim:schemas:core:1.0:Printer', ['serialNumber']], [$type->schema->id, self::names($type->schema)]);
        self::assertTrue($type->schema->attribute('serialNumber')?->caseExact);
        self::assertSame(['PrinterSite', ['room']], [$type->schemaExtensions[0]->schema->name, self::names($type->schemaExtensions[0]->schema)]);
    }

    /**
     * @return iterable<string, array{class-string, string}> the error with %s for the class name
     */
    public static function invalidRepositories(): iterable
    {
        yield 'no attribute' => [RepositoryDecorator::class, '%s: the class has no #[AsResourceRepository] attribute.'];
        yield 'not a repository' => [RawNotRepository::class, '%s: #[AsResourceRepository] is on a class that does not implement TacticMedia\ScimServer\Repository\ResourceRepository.'];
        yield 'custom type without schema' => [RawCustomTypeWithoutSchema::class, "%s: resource type \"Printer\" is not defined by RFC 7643. Set #[AsResourceRepository('Printer', schema: '<core schema URN>', schemas: [...])]."];
        yield 'unknown core schema' => [RawUnknownSchema::class, '%s: schema: urn:acme:scim:schemas:core:1.0:Printer is not an RFC 7643 core schema. Add its Schema to schemas:.'];
        yield 'invalid path' => [RawInvalidPath::class, '%s: "name..givenName" is not a valid path: \'name..givenName\' is not a valid path. Expected attrPath, attrPath[valFilter] or attrPath[valFilter].subAttr.'];
        yield 'value filter' => [RawValueFilter::class, '%s: "emails[primary eq true].value" has a value filter that is not allowed. Use [type eq "<value>"].'];
        yield 'invalid endpoint' => [RawInvalidEndpoint::class, '%s: endpoint "Users" is not valid. Use "/" and one name, for example "/Devices".'];
        yield 'reserved endpoint' => [RawReservedEndpoint::class, '%s: endpoint "/Schemas" is reserved by RFC 7644. Set another endpoint:.'];
        yield 'unknown attribute' => [RawUnknownAttribute::class, '%s: "nickNam" is not an attribute of urn:ietf:params:scim:schemas:core:2.0:User or urn:ietf:params:scim:schemas:extension:enterprise:2.0:User.'];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('invalidRepositories')]
    #[TestDox('$class fails with an error that names the fix')]
    public function invalidRepositoryFails(string $class, string $error): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf($error, $class));

        new RawResourceTypeLoader()->load($class);
    }

    /**
     * @return list<string>
     */
    private static function names(Schema|AttributeDefinition|null $parent): array
    {
        $attributes = $parent instanceof Schema ? $parent->attributes : $parent->subAttributes ?? [];

        return array_map(static fn (AttributeDefinition $attribute): string => $attribute->name, $attributes);
    }
}
