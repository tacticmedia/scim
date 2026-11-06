<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Repository;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServer\Repository\DocumentSorter;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Tests\Filter\AssertsScimException;

final class DocumentSorterTest extends KernelTestCase
{
    use AssertsScimException;

    /**
     * @return iterable<string, array{string, SortOrder, list<string>, list<string>}>
     */
    public static function crossTypeCases(): iterable
    {
        yield 'an attribute that both types define' => ['displayName', SortOrder::Ascending, ['user', 'group'], ['g2', 'u2', 'u1', 'g1']];
        yield 'descending' => ['displayName', SortOrder::Descending, ['user', 'group'], ['g1', 'u1', 'u2', 'g2']];
        yield 'a type without the attribute sorts last when ascending' => ['userName', SortOrder::Ascending, ['user', 'group'], ['u2', 'u1', 'g1', 'g2']];
        yield 'an attribute that no type defines keeps the input order' => ['shoeSize', SortOrder::Ascending, ['user', 'group'], ['u1', 'u2', 'g1', 'g2']];
        yield 'the first type that defines it gives the value type: strings do not fit an integer' => ['displayName', SortOrder::Ascending, ['device', 'user'], ['d2', 'd1', 'u1', 'u2']];
    }

    /**
     * @param list<string> $types       the resource types of the entries, in order
     * @param list<string> $expectedIds
     */
    #[Test]
    #[DataProvider('crossTypeCases')]
    #[TestDox('sortAcrossTypes(): $_dataName')]
    public function sortsAcrossTypes(string $sortBy, SortOrder $order, array $types, array $expectedIds): void
    {
        $entries = array_merge(...array_map($this->entries(...), $types));
        $path = AttributePath::parse($sortBy);
        self::assertInstanceOf(AttributePath::class, $path);

        $sorted = new DocumentSorter()->sortAcrossTypes($entries, $path, $order);

        self::assertSame($expectedIds, array_map(static fn (array $entry): mixed => $entry[1]['id'], $sorted));
    }

    #[Test]
    #[TestDox('keys() rejects password with invalidValue')]
    public function rejectsPassword(): void
    {
        $users = [['id' => 'u1', 'password' => 'I'], ['id' => 'u2', 'password' => 'G']];

        self::assertThrowsScimException(
            ScimErrorType::InvalidValue,
            'The sortBy attribute "urn:ietf:params:scim:schemas:core:2.0:User:password" is never returned and cannot be used to sort.',
            static fn (): mixed => new DocumentSorter()->keys($users, new AttributePath(null, 'password'), CoreResourceTypes::user()),
        );
    }

    /**
     * @return list<array{ResourceType, array<string, mixed>}>
     */
    private function entries(string $type): array
    {
        return match ($type) {
            'user' => [
                [CoreResourceTypes::user(), ['id' => 'u1', 'userName' => 'bjensen', 'displayName' => 'Barbara Jensen']],
                [CoreResourceTypes::user(), ['id' => 'u2', 'userName' => 'asmith', 'displayName' => 'Alice Smith']],
            ],
            'group' => [
                [CoreResourceTypes::group(), ['id' => 'g1', 'displayName' => 'Tour Guides']],
                [CoreResourceTypes::group(), ['id' => 'g2', 'displayName' => 'Admins']],
            ],
            default => [
                [$this->device(), ['id' => 'd1', 'displayName' => 7]],
                [$this->device(), ['id' => 'd2', 'displayName' => 3]],
            ],
        };
    }

    private function device(): ResourceType
    {
        return new ResourceType('Device', '/Devices', new Schema('urn:example:scim:schemas:2.0:Device', 'Device', 'Device', [
            new AttributeDefinition('displayName', AttributeType::Integer),
        ]));
    }
}
