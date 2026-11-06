<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Tests\Fixtures\ArrayObjectStore;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\IntGroup;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\IntUser;

final class CollectionWriterTest extends KernelTestCase
{
    use CreatesDocumentMapper;

    private MappingRegistry $mappings;

    private ArrayObjectStore $store;

    private User $alice;

    private User $bob;

    private Group $staff;

    #[\Override]
    protected function setUp(): void
    {
        $this->mappings = self::kernelMappings();
        $this->alice = new User('alice');
        $this->bob = new User('bob');
        $this->staff = new Group('Staff');
        $this->store = new ArrayObjectStore()
            ->add($this->mappings->get('User'), $this->alice, $this->bob)
            ->add($this->mappings->get('Group'), $this->staff);
    }

    #[Test]
    #[TestDox('Typed members go to their collection, and only added ids are looked up')]
    public function typedMembersGoToTheirCollection(): void
    {
        $group = new Group('Admins');
        $group->addUser($this->alice);

        $this->write($group, [['value' => $this->id($this->alice), 'type' => 'User'], ['value' => $this->id($this->bob), 'type' => 'user'], ['value' => $this->id($this->staff), 'type' => 'Group']]);

        self::assertSame([[$this->alice, $this->bob], [$this->staff]], [$group->getUsers()->getValues(), $group->getSubgroups()->getValues()]);
        self::assertSame([['User', [$this->id($this->bob)]], ['Group', [$this->id($this->staff)]]], $this->store->lookups);
    }

    #[Test]
    #[TestDox('Untyped new ids are resolved in declaration order: ids not found in the first target go to the next')]
    public function untypedMembersAreResolvedInDeclarationOrder(): void
    {
        $group = new Group('Admins');

        $this->write($group, [['value' => $this->id($this->staff)], ['value' => $this->id($this->alice)], ['value' => $this->id($this->alice)]]);

        self::assertSame([[$this->alice], [$this->staff]], [$group->getUsers()->getValues(), $group->getSubgroups()->getValues()]);
        self::assertSame([['User', [$this->id($this->staff), $this->id($this->alice)]], ['Group', [$this->id($this->staff)]]], $this->store->lookups);
    }

    #[Test]
    #[TestDox('Members that the document omits go through the remover, kept members need no lookup')]
    public function omittedMembersAreRemoved(): void
    {
        $group = new Group('Admins');
        $group->addUser($this->alice);
        $group->addUser($this->bob);
        $group->addSubgroup($this->staff);

        $this->write($group, [['value' => $this->id($this->bob)]]);

        self::assertSame([[$this->bob], [], []], [$group->getUsers()->getValues(), $group->getSubgroups()->getValues(), $this->store->lookups]);
    }

    #[Test]
    #[TestDox('A document without members empties every collection')]
    public function absentMembersEmptyTheCollections(): void
    {
        $group = new Group('Admins');
        $group->addUser($this->alice);
        $group->addSubgroup($this->staff);

        self::mapper($this->mappings, $this->store)->write($this->mappings->get('Group'), ['displayName' => 'Admins'], $group, false);

        self::assertSame([[], []], [$group->getUsers()->getValues(), $group->getSubgroups()->getValues()]);
    }

    /**
     * @param array{value: string, type?: string} $member
     */
    #[Test]
    #[TestDox('An id that no target store knows is 400 invalidValue')]
    #[DataProvider('unknownMembers')]
    public function unknownMemberFails(array $member, string $detail): void
    {
        try {
            $this->write(new Group('Admins'), [$member]);
            self::fail('No exception.');
        } catch (ScimException $scimException) {
            self::assertSame([400, 'invalidValue', $detail], [$scimException->status, $scimException->scimType?->value, $scimException->detail]);
        }
    }

    /**
     * @return iterable<string, array{array{value: string, type?: string}, string}>
     */
    public static function unknownMembers(): iterable
    {
        yield 'typed' => [['value' => 'nope', 'type' => 'User'], 'Attribute "members": "nope" is not the id of a User.'];
        yield 'untyped' => [['value' => 'nope'], 'Attribute "members": "nope" is not the id of a User or Group.'];
    }

    #[Test]
    #[TestDox('Numeric ids stay strings for the store, and array collections use the adder and remover')]
    public function numericIdsStayStrings(): void
    {
        $mappings = self::mappings(['User' => IntUser::class, 'Group' => IntGroup::class]);
        $first = new IntUser();
        $first->id = 1;

        $second = new IntUser();
        $second->id = 2;

        $third = new IntUser();
        $third->id = 3;

        $store = new ArrayObjectStore()->add($mappings->get('User'), $first, $second, $third);
        $group = new IntGroup();
        $group->addUser($third);

        self::mapper($mappings, $store)->write($mappings->get('Group'), ['displayName' => 'Admins', 'members' => [['value' => '1'], ['value' => '2', 'type' => 'User']]], $group, false);

        self::assertSame([[$second, $first], [['User', ['2']], ['User', ['1']]]], [$group->users, $store->lookups]);
    }

    /**
     * @param list<array<string, string>> $members
     */
    private function write(Group $group, array $members): void
    {
        self::mapper($this->mappings, $this->store)->write($this->mappings->get('Group'), ['displayName' => 'Admins', 'members' => $members], $group, false);
    }

    private function id(User|Group $object): string
    {
        return (string) $object->getId();
    }
}
