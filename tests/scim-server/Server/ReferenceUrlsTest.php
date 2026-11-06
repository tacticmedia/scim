<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Server\ReferenceUrls;

final class ReferenceUrlsTest extends KernelTestCase
{
    private const string BASE_URL = 'https://example.com/scim/v2';
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function members(): iterable
    {
        yield 'type User' => [['value' => 'u1', 'type' => 'User'], ['value' => 'u1', 'type' => 'User', '$ref' => self::BASE_URL.'/Users/u1']];
        yield 'type in another case' => [['value' => 'g2', 'type' => 'group'], ['value' => 'g2', 'type' => 'group', '$ref' => self::BASE_URL.'/Groups/g2']];
        yield 'id with reserved characters' => [['value' => 'a/b c', 'type' => 'User'], ['value' => 'a/b c', 'type' => 'User', '$ref' => self::BASE_URL.'/Users/a%2Fb%20c']];
        yield '"$ref": null' => [['$ref' => null, 'value' => 'u1', 'type' => 'User'], ['$ref' => self::BASE_URL.'/Users/u1', 'value' => 'u1', 'type' => 'User']];
        yield 'a $ref of the repository' => [['value' => 'u1', 'type' => 'User', '$ref' => '../Users/u1'], ['value' => 'u1', 'type' => 'User', '$ref' => '../Users/u1']];
        yield 'no type: two referenceTypes' => [['value' => 'u1'], ['value' => 'u1']];
        yield 'a type that no referenceType names' => [['value' => 'u1', 'type' => 'Device'], ['value' => 'u1', 'type' => 'Device']];
        yield 'no value' => [['type' => 'User'], ['type' => 'User']];
        yield 'an empty value' => [['value' => '', 'type' => 'User'], ['value' => '', 'type' => 'User']];
        yield 'a value that is not a string' => [['value' => 7, 'type' => 'User'], ['value' => 7, 'type' => 'User']];
    }

    /**
     * @param array<string, mixed> $member
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('members')]
    #[TestDox('Group member: $_dataName')]
    public function fillsMemberReference(array $member, array $expected): void
    {
        $urls = new ReferenceUrls(self::BASE_URL, [CoreResourceTypes::user(), CoreResourceTypes::group()]);

        self::assertSame(
            ['id' => 'g1', 'members' => [$expected, 'u9']],
            $urls->fill(['id' => 'g1', 'members' => [$member, 'u9']], CoreResourceTypes::group()),
        );
    }

    #[Test]
    #[TestDox('Fills User groups.$ref from referenceTypes, as "direct" names no resource type, and manager.$ref; leaves photos alone')]
    public function fillsUserReferences(): void
    {
        $urls = new ReferenceUrls(self::BASE_URL, [CoreResourceTypes::user(), CoreResourceTypes::group()]);
        $photos = [['value' => 'https://photos.example.com/u2.jpg', 'type' => 'photo']];

        self::assertSame(
            [
                'id' => 'u2',
                'photos' => $photos,
                'groups' => [['value' => 'g1', 'type' => 'direct', '$ref' => self::BASE_URL.'/Groups/g1']],
                self::ENTERPRISE => ['manager' => ['value' => 'u1', '$ref' => self::BASE_URL.'/Users/u1']],
            ],
            $urls->fill([
                'id' => 'u2',
                'photos' => $photos,
                'groups' => [['value' => 'g1', 'type' => 'direct']],
                self::ENTERPRISE => ['manager' => ['value' => 'u1']],
            ], CoreResourceTypes::user()),
        );
    }

    #[Test]
    #[TestDox('Leaves a reference to a resource type that is not known')]
    public function leavesReferenceToUnknownType(): void
    {
        $document = ['id' => 'u2', 'groups' => [['value' => 'g1']]];

        self::assertSame($document, new ReferenceUrls(self::BASE_URL, [CoreResourceTypes::user()])->fill($document, CoreResourceTypes::user()));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function valuesOfTheWrongShape(): iterable
    {
        yield 'extension that is not an object' => [['id' => 'u2', self::ENTERPRISE => 'x']];
        yield 'manager that is not an object' => [['id' => 'u2', self::ENTERPRISE => ['manager' => 'u1']]];
    }

    /**
     * @param array<string, mixed> $document
     */
    #[Test]
    #[DataProvider('valuesOfTheWrongShape')]
    #[TestDox('Passes through an $_dataName')]
    public function passesThroughValuesOfTheWrongShape(array $document): void
    {
        self::assertSame($document, new ReferenceUrls(self::BASE_URL, [CoreResourceTypes::user()])->fill($document, CoreResourceTypes::user()));
    }
}
