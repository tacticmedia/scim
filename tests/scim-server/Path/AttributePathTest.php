<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Path;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Path\AttributePath;

final class AttributePathTest extends KernelTestCase
{
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    /**
     * @return iterable<string, array{string, ?string, string, ?string}>
     */
    public static function validPaths(): iterable
    {
        yield 'attribute' => ['userName', null, 'userName', null];
        yield 'digits, hyphen and underscore' => ['x509Certificates.a-b_c', null, 'x509Certificates', 'a-b_c'];
        yield '$ref' => ['members.$ref', null, 'members', '$ref'];
        yield '$ref in another case' => ['members.$REF', null, 'members', '$REF'];
        yield 'extension URN with sub-attribute' => [self::ENTERPRISE.':manager.value', self::ENTERPRISE, 'manager', 'value'];
        yield 'URN prefix in another case' => ['URN:Example:Attr', 'URN:Example', 'Attr', null];
        yield 'schema URN alone splits at the last colon' => [self::ENTERPRISE, 'urn:ietf:params:scim:schemas:extension:enterprise:2.0', 'User', null];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validPathStrings(): iterable
    {
        foreach (self::validPaths() as $name => [$path]) {
            yield $name => [$path];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'leading digit' => ['1userName'];
        yield 'dollar name other than $ref' => ['members.$value'];
        yield 'two sub-attributes' => ['name.givenName.first'];
        yield 'empty sub-attribute' => ['name.'];
        yield 'space' => ['user Name'];
        yield 'trailing newline' => ["userName\n"];
        yield 'colon without URN prefix' => ['schema:userName'];
        yield 'URN without attribute' => ['urn:example:'];
    }

    #[Test]
    #[DataProvider('validPaths')]
    #[TestDox('parse() splits $path')]
    public function parseSplitsAValidPath(string $path, ?string $schemaUrn, string $attribute, ?string $subAttribute): void
    {
        self::assertEquals(new AttributePath($schemaUrn, $attribute, $subAttribute), AttributePath::parse($path));
    }

    #[Test]
    #[DataProvider('invalidPaths')]
    #[TestDox('parse() rejects $path')]
    public function parseRejectsAnInvalidPath(string $path): void
    {
        self::assertNull(AttributePath::parse($path));
    }

    #[Test]
    #[DataProvider('validPathStrings')]
    #[TestDox('__toString() gives back $path')]
    public function toStringGivesBackTheParsedPath(string $path): void
    {
        self::assertSame($path, (string) AttributePath::parse($path));
    }

    #[Test]
    #[TestDox('equals() ignores case')]
    public function equalsIgnoresCase(): void
    {
        self::assertTrue(new AttributePath(self::ENTERPRISE, 'manager', 'value')->equals(new AttributePath(strtoupper(self::ENTERPRISE), 'MANAGER', 'Value')));
    }

    #[Test]
    #[TestDox('equals() tells apart the schema URN and the sub-attribute')]
    public function equalsTellsApartTheSchemaUrnAndTheSubAttribute(): void
    {
        $path = new AttributePath(self::ENTERPRISE, 'manager', 'value');

        self::assertFalse($path->equals(new AttributePath(null, 'manager', 'value')));
        self::assertFalse($path->equals(new AttributePath(self::ENTERPRISE, 'manager')));
    }

    #[Test]
    #[TestDox('parent() drops the sub-attribute')]
    public function parentDropsTheSubAttribute(): void
    {
        self::assertEquals(new AttributePath(self::ENTERPRISE, 'manager'), new AttributePath(self::ENTERPRISE, 'manager', 'value')->parent());
    }

    #[Test]
    #[TestDox('withSubAttribute() replaces the sub-attribute')]
    public function withSubAttributeReplacesTheSubAttribute(): void
    {
        $path = new AttributePath(self::ENTERPRISE, 'manager', 'value');

        self::assertEquals(new AttributePath(self::ENTERPRISE, 'manager', '$ref'), $path->withSubAttribute('$ref'));
        self::assertEquals(new AttributePath(self::ENTERPRISE, 'manager'), $path->withSubAttribute(null));
    }
}
