<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Patch;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;

final class PatchApplierScaleTest extends KernelTestCase
{
    private const int MEMBERS = 3000;
    private const float MAX_SECONDS = 1.5;

    /**
     * @return iterable<string, array{string, list<array{value: string}>, list<array{value: string}>|null}>
     */
    public static function memberLists(): iterable
    {
        $stored = self::members('s');
        $added = self::members('a');

        yield 'add' => ['add', $added, [...$stored, ...$added]];
        yield 'remove' => ['remove', $stored, null];
    }

    /**
     * @param list<array{value: string}>      $value
     * @param list<array{value: string}>|null $expected
     */
    #[Test]
    #[DataProvider('memberLists')]
    #[TestDox('Adds and removes thousands of members in linear time')]
    public function changesManyMembersInLinearTime(string $operation, array $value, ?array $expected): void
    {
        $body = PatchFixtures::body(json_encode(['op' => $operation, 'path' => 'members', 'value' => $value], \JSON_THROW_ON_ERROR));
        $group = PatchFixtures::group();
        $group['members'] = self::members('s');

        $start = hrtime(true);
        $members = PatchFixtures::patch($group, $body, CoreResourceTypes::group())['members'] ?? null;
        $seconds = (hrtime(true) - $start) / 1e9;

        self::assertSame($expected, $members);
        self::assertLessThan(self::MAX_SECONDS, $seconds);
    }

    /**
     * @return list<array{value: string}>
     */
    private static function members(string $prefix): array
    {
        return array_map(static fn (int $index): array => ['value' => $prefix.$index], range(1, self::MEMBERS));
    }
}
