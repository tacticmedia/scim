<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Schema\CoreSchemas;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Schema\SchemaExtension;

final class ResourceTypeTest extends KernelTestCase
{
    private const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function userPaths(): iterable
    {
        yield 'core name' => ['userName', self::USER.':userName'];
        yield 'core name in another case' => ['USERNAME', self::USER.':userName'];
        yield 'core sub-attribute in another case' => ['NAME.GIVENNAME', self::USER.':name.givenName'];
        yield '$ref' => ['groups.$ref', self::USER.':groups.$ref'];
        yield 'core URN' => [self::USER.':userName', self::USER.':userName'];
        yield 'core URN in another case' => ['URN:IETF:PARAMS:SCIM:SCHEMAS:CORE:2.0:USER:USERNAME', self::USER.':userName'];
        yield 'extension URN' => [self::ENTERPRISE.':employeeNumber', self::ENTERPRISE.':employeeNumber'];
        yield 'extension URN in another case' => ['urn:ietf:params:scim:schemas:extension:ENTERPRISE:2.0:user:MANAGER.$REF', self::ENTERPRISE.':manager.$ref'];
        yield 'unqualified extension name' => ['employeeNumber', self::ENTERPRISE.':employeeNumber'];
        yield 'unqualified extension sub-attribute' => ['manager.displayName', self::ENTERPRISE.':manager.displayName'];
        yield 'common id' => ['id', self::USER.':id'];
        yield 'common externalId in another case' => ['EXTERNALID', self::USER.':externalId'];
        yield 'common meta sub-attribute' => ['meta.lastModified', self::USER.':meta.lastModified'];
        yield 'common name with core URN' => [self::USER.':externalId', self::USER.':externalId'];
        yield 'unknown name' => ['unknown', null];
        yield 'unknown sub-attribute' => ['name.nickName', null];
        yield 'sub-attribute of a simple attribute' => ['userName.value', null];
        yield 'unknown schema URN' => ['urn:example:Unknown:userName', null];
        yield 'core name with extension URN' => [self::ENTERPRISE.':userName', null];
        yield 'common name with extension URN' => [self::ENTERPRISE.':id', null];
        yield 'schema URN alone' => [self::ENTERPRISE, null];
    }

    #[Test]
    #[DataProvider('userPaths')]
    #[TestDox('resolve() of $path on User gives $expected')]
    public function resolveGivesTheQualifiedPathInSchemaSpelling(string $path, ?string $expected): void
    {
        self::assertSame($expected, $this->resolve(CoreResourceTypes::user(), $path)?->path()->__toString());
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function selections(): iterable
    {
        $enterprise = array_map(static fn (string $name): string => self::ENTERPRISE.':'.$name, ['employeeNumber', 'costCenter', 'organization', 'division', 'department', 'manager']);

        yield 'extension URN' => [self::ENTERPRISE, $enterprise];
        yield 'extension URN in another case' => [strtoupper(self::ENTERPRISE), $enterprise];
        yield 'attribute' => ['userName', [self::USER.':userName']];
        yield 'core schema URN' => [self::USER, []];
        yield 'unknown name' => ['shoeSize', []];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('selections')]
    #[TestDox('resolveSelection() on User: $_dataName')]
    public function resolveSelectionExpandsAnExtensionUrn(string $path, array $expected): void
    {
        $attributePath = AttributePath::parse($path);
        self::assertNotNull($attributePath);

        self::assertSame($expected, array_map(static fn (ResolvedAttribute $attribute): string => (string) $attribute->path(), CoreResourceTypes::user()->resolveSelection($attributePath)));
    }

    #[Test]
    #[TestDox('resolve() of an unqualified name that two extensions define gives null')]
    public function resolveOfANameThatTwoExtensionsDefineGivesNull(): void
    {
        $resourceType = new ResourceType('User', '/Users', CoreSchemas::user(), [
            new SchemaExtension(new Schema('urn:example:A', 'A', '', [new AttributeDefinition('costCenter')])),
            new SchemaExtension(new Schema('urn:example:B', 'B', '', [new AttributeDefinition('costCenter')])),
        ]);

        self::assertNull($this->resolve($resourceType, 'costCenter'));
        self::assertSame('urn:example:B:costCenter', $this->resolve($resourceType, 'urn:example:B:costCenter')?->path()->__toString());
    }

    #[Test]
    #[TestDox('resolve() of an unqualified name prefers the core schema to an extension')]
    public function resolvePrefersTheCoreSchemaToAnExtension(): void
    {
        $resourceType = new ResourceType('User', '/Users', CoreSchemas::user(), [
            new SchemaExtension(new Schema('urn:example:A', 'A', '', [new AttributeDefinition('displayName')])),
        ]);

        self::assertSame(self::USER.':displayName', $this->resolve($resourceType, 'displayName')?->path()->__toString());
    }

    #[Test]
    #[TestDox('resolve() prefers a common attribute to its listing in the core schema')]
    public function resolvePrefersACommonAttributeToItsListingInTheCoreSchema(): void
    {
        $resourceType = new ResourceType('Device', '/Devices', new Schema('urn:example:Device', 'Device', '', [new AttributeDefinition('id')]));

        self::assertSame(Mutability::ReadOnly, $this->resolve($resourceType, 'id')?->definition()->mutability);
    }

    #[Test]
    #[TestDox('extension() matches the schema URN in any case')]
    public function extensionMatchesTheSchemaUrnInAnyCase(): void
    {
        $resourceType = CoreResourceTypes::user();

        self::assertSame($resourceType->schemaExtensions[0], $resourceType->extension(strtoupper(self::ENTERPRISE)));
        self::assertNull($resourceType->extension(self::USER));
    }

    #[Test]
    #[TestDox('schemas() lists the core schema, then the extensions')]
    public function schemasListsTheCoreSchemaThenTheExtensions(): void
    {
        $resourceType = CoreResourceTypes::user();

        self::assertSame([$resourceType->schema, $resourceType->schemaExtensions[0]->schema], $resourceType->schemas());
    }

    private function resolve(ResourceType $resourceType, string $path): ?ResolvedAttribute
    {
        $attributePath = AttributePath::parse($path);
        self::assertNotNull($attributePath);

        return $resourceType->resolve($attributePath);
    }
}
