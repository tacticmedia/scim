<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Patch;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Patch\ValueIndex;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;

final class ValueIndexTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{list<mixed>, mixed, int|null}>
     */
    public static function lookups(): iterable
    {
        yield 'first of two groups' => [[['value' => 'a', 'type' => 'work'], ['value' => 'a']], ['value' => 'a'], 0];
        yield 'value without the type of the entry' => [[['value' => 'a', 'type' => 'work'], ['value' => 'a']], ['value' => 'a', 'type' => 'home'], 1];
        yield 'value without the primary of the entry' => [[['value' => 'a']], ['value' => 'a', 'primary' => true], null];
        yield 'entry without value' => [[['value' => 'a', 'type' => 'work']], ['type' => 'Work'], 0];
        yield 'value without the sub-attribute of an entry without value' => [[['value' => 'a']], ['type' => 'work'], null];
        yield 'entry that is not an object' => [[['value' => 'a']], 'a', null];
        yield 'value that is not an object' => [['a', ['value' => 'a']], ['value' => 'a'], 1];
        yield 'sub-value of the wrong type' => [[['value' => 1], ['value' => '1']], ['value' => '1'], 1];
        yield 'entry sub-value of the wrong type' => [[['value' => 'a', 'primary' => true]], ['value' => 'a', 'primary' => 'yes'], null];
    }

    /**
     * @param list<mixed> $values
     */
    #[Test]
    #[DataProvider('lookups')]
    #[TestDox('Finds the first value that contains an entry')]
    public function findsFirstContainingValue(array $values, mixed $entry, ?int $expected): void
    {
        self::assertSame($expected, new ValueIndex($this->emails(), $values)->first($entry));
    }

    #[Test]
    #[TestDox('Finds a value after a change of its sub-attribute moves it to another group')]
    public function findsChangedValue(): void
    {
        $values = new ValueIndex($this->emails(), [['value' => 'a'], ['value' => 'a', 'primary' => false]]);
        self::assertSame(0, $values->first(['value' => 'a']));

        $values->set(0, 'primary', true);

        self::assertSame([0, 0, 1], [$values->first(['value' => 'a']), $values->first(['value' => 'a', 'primary' => true]), $values->first(['value' => 'a', 'primary' => false])]);
        self::assertSame([['value' => 'a', 'primary' => true], ['value' => 'a', 'primary' => false]], $values->values());
    }

    #[Test]
    #[TestDox('Removes every value that contains an entry')]
    public function removesContainingValues(): void
    {
        $values = new ValueIndex($this->emails(), [['value' => 'a'], ['value' => 'b'], ['value' => 'A', 'type' => 'work']]);

        $values->remove(['value' => 'a']);

        self::assertSame([[['value' => 'b']], null], [$values->values(), $values->first(['value' => 'a'])]);
    }

    #[Test]
    #[TestDox('Compares simple values as a filter compares them')]
    public function comparesSimpleValues(): void
    {
        $values = new ValueIndex(new AttributeDefinition('tags', multiValued: true), ['Blue', 'red', 1]);

        $values->remove('RED');
        $values->append('green');

        self::assertSame([0, null, 3, null], [$values->first('blue'), $values->first('red'), $values->first('Green'), $values->first(1)]);
        self::assertSame(['Blue', 1, 'green'], $values->values());
    }

    private function emails(): AttributeDefinition
    {
        return new AttributeDefinition('emails', AttributeType::Complex, multiValued: true, subAttributes: [
            new AttributeDefinition('value'),
            new AttributeDefinition('type'),
            new AttributeDefinition('primary', AttributeType::Boolean),
        ]);
    }
}
