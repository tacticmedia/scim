<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Patch;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Tests\Filter\AssertsScimException;

final class PatchApplierMergeTest extends KernelTestCase
{
    use AssertsScimException;
    private const array NAME = ['givenName' => 'Barbara', 'familyName' => 'Jensen', 'middleName' => 'Jane'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesWithoutSubAttributes(): iterable
    {
        yield 'add of an empty object' => ['{"op":"add","path":"name","value":{}}'];
        yield 'add of an unknown sub-attribute' => ['{"op":"add","path":"name","value":{"foo":"x"}}'];
        yield 'path-less replace of an unknown sub-attribute' => ['{"op":"replace","value":{"name":{"foo":"x"}}}'];
        yield 'replace of a readOnly sub-attribute' => ['{"op":"replace","path":"'.PatchFixtures::ENTERPRISE.':manager","value":{"displayName":"Boss"}}'];
        yield 'add of an empty object to a selected value' => ['{"op":"add","path":"emails[type eq \"work\"]","value":{}}'];
        yield 'add of an empty object to a value filter that selects nothing' => ['{"op":"add","path":"emails[type eq \"home\"]","value":{}}'];
        yield 'add of a null sub-attribute to a value filter that selects nothing' => ['{"op":"add","path":"emails[type eq \"home\"]","value":{"display":null}}'];
    }

    #[Test]
    #[DataProvider('valuesWithoutSubAttributes')]
    #[TestDox('Keeps the stored value for an object without sub-attributes to set (RFC 7644 section 3.5.2.3)')]
    public function keepsValueForObjectWithoutSubAttributes(string $operation): void
    {
        $user = $this->userWithMiddleName();

        self::assertSame($user, $this->patch($user, $operation));
    }

    #[Test]
    #[TestDox('Keeps a member for an add of only a readOnly sub-attribute')]
    public function keepsMemberForReadOnlySubAttribute(): void
    {
        $group = PatchFixtures::group(['u1', 'u2']);

        self::assertSame($group, PatchFixtures::patch($group, PatchFixtures::body('{"op":"add","path":"members[value eq \"u1\"]","value":{"display":"Babs"}}'), CoreResourceTypes::group()));
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function nullSubAttributes(): iterable
    {
        yield 'replace with only a null sub-attribute' => ['{"op":"replace","path":"name","value":{"middleName":null}}', ['givenName' => 'Barbara', 'familyName' => 'Jensen']];
        yield 'replace with a null sub-attribute' => ['{"op":"replace","path":"name","value":{"givenName":"B2","middleName":null}}', ['givenName' => 'B2', 'familyName' => 'Jensen']];
        yield 'add with a null sub-attribute' => ['{"op":"add","path":"name","value":{"givenName":"B2","MiddleName":null}}', ['givenName' => 'B2', 'familyName' => 'Jensen']];
        yield 'path-less replace with a null sub-attribute' => [
            '{"op":"replace","value":{"name":{"givenName":"B2","familyName":"Jensen","middleName":null}}}',
            ['givenName' => 'B2', 'familyName' => 'Jensen'],
        ];
        yield 'replace with a null absent sub-attribute' => ['{"op":"replace","path":"name","value":{"honorificPrefix":null}}', self::NAME];
    }

    /**
     * @param array<string, string> $expected
     */
    #[Test]
    #[DataProvider('nullSubAttributes')]
    #[TestDox('Unassigns a null sub-attribute of a merged value and keeps the others (RFC 7643 section 2.5)')]
    public function unassignsNullSubAttribute(string $operation, array $expected): void
    {
        self::assertSame($expected, $this->patch($this->userWithMiddleName(), $operation)['name']);
    }

    #[Test]
    #[TestDox('Unassigns a null sub-attribute of a selected value')]
    public function unassignsNullSubAttributeOfSelectedValue(): void
    {
        $user = PatchFixtures::user();
        $user['emails'][0]['display'] = 'Work';

        self::assertSame(
            [['value' => 'n@example.org', 'type' => 'work', 'primary' => true]],
            $this->patch($user, '{"op":"add","path":"emails[type eq \"work\"]","value":{"display":null,"value":"n@example.org"}}')['emails'],
        );
    }

    #[Test]
    #[TestDox('Unassigns the whole name when every stored sub-attribute is null')]
    public function unassignsNameWithEveryStoredSubAttributeNull(): void
    {
        self::assertArrayNotHasKey('name', $this->patch($this->userWithMiddleName(), '{"op":"add","path":"name","value":{"givenName":null,"familyName":null,"middleName":null}}'));
    }

    #[Test]
    #[TestDox('Rejects a null immutable sub-attribute with a stored value')]
    public function rejectsNullImmutableSubAttribute(): void
    {
        self::assertThrowsScimException(
            ScimErrorType::Mutability,
            'Operations[0]: Attribute "'.PatchFixtures::GROUP.':members.value" is immutable.',
            static fn (): array => PatchFixtures::patch(PatchFixtures::group(['u1']), PatchFixtures::body('{"op":"add","path":"members[value eq \"u1\"]","value":{"value":null}}'), CoreResourceTypes::group()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function userWithMiddleName(): array
    {
        $user = PatchFixtures::user();
        $user['name'] = self::NAME;

        return $user;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function patch(array $document, string $operation): array
    {
        return PatchFixtures::patch($document, PatchFixtures::body($operation), CoreResourceTypes::user());
    }
}
