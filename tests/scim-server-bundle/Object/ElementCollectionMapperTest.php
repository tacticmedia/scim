<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\UserAddress;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\LazyMailbox;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\Mailbox;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\MailboxEmail;

final class ElementCollectionMapperTest extends KernelTestCase
{
    use CreatesDocumentMapper;

    #[Test]
    #[TestDox('Read renders each element with its present sub-attributes in schema order, and skips an element without one')]
    public function readsTheElements(): void
    {
        $mailbox = new Mailbox();
        $mailbox->addEmail($this->email($mailbox, 'a@example.com', 'work', true));
        $mailbox->addEmail($this->email($mailbox, 'b@example.com', null, false));

        $user = new User('bjensen');
        $user->addAddress(new UserAddress($user));

        self::assertSame(
            [['value' => 'a@example.com', 'type' => 'work', 'primary' => true], ['value' => 'b@example.com', 'primary' => false]],
            self::mapper(self::mappings(['User' => Mailbox::class]))->toDocument($this->mailboxes(), $mailbox)['emails'] ?? null,
        );
        self::assertSame([['primary' => false]], self::mapper(self::kernelMappings())->toDocument(self::kernelMappings()->get('User'), $user)['addresses'] ?? null);
        self::assertArrayNotHasKey('emails', self::mapper(self::mappings(['User' => Mailbox::class]))->toDocument($this->mailboxes(), new Mailbox()));
    }

    #[Test]
    #[TestDox('Read omits a null element collection')]
    public function omitsNullCollection(): void
    {
        $mappings = self::mappings(['User' => LazyMailbox::class]);

        self::assertArrayNotHasKey('emails', self::mapper($mappings)->toDocument($mappings->get('User'), new LazyMailbox()));
    }

    #[Test]
    #[TestDox('A create builds each element through its constructor, with the owner and the sub-attributes by name, and adds it')]
    public function createBuildsTheElements(): void
    {
        $mailbox = new Mailbox();

        $this->write($mailbox, [['value' => 'a@example.com', 'type' => 'work', 'primary' => true], ['value' => 'b@example.com', 'display' => 'B']]);

        self::assertSame(
            [[$mailbox, 'a@example.com', 'work', null, true, 'mail'], [$mailbox, 'b@example.com', null, 'B', false, 'mail']],
            array_map(static fn (MailboxEmail $email): array => [$email->mailbox, $email->value, $email->type, $email->display, $email->primary, $email->label], $mailbox->getEmails()),
        );
    }

    #[Test]
    #[TestDox('A replace updates the elements that match by value and type in any case, removes the others and adds the new ones')]
    public function replaceMatchesByValueAndType(): void
    {
        $mailbox = new Mailbox();
        $mailbox->addEmail($work = $this->email($mailbox, 'a@example.com', 'work', true));
        $mailbox->addEmail($this->email($mailbox, 'b@example.com', 'home', false));

        $this->write($mailbox, [['value' => 'c@example.com', 'type' => 'other'], ['value' => 'A@Example.com', 'type' => 'WORK', 'display' => 'A']]);

        self::assertSame(
            [[$work, 'A@Example.com', 'WORK', 'A', false], ['c@example.com', 'other', null, false]],
            [[$mailbox->getEmails()[0], $work->value, $work->type, $work->display, $work->primary], [$mailbox->getEmails()[1]->value, $mailbox->getEmails()[1]->type, $mailbox->getEmails()[1]->display, $mailbox->getEmails()[1]->primary]],
        );
    }

    #[Test]
    #[TestDox('Elements without "value" match by "type", in order, and an absent attribute removes every element')]
    public function matchesByTypeAndRemovesAll(): void
    {
        $mappings = self::kernelMappings();
        $user = new User('bjensen');
        $user->addAddress($first = new UserAddress($user, 'work'));
        $user->addAddress(new UserAddress($user, 'work'));

        self::mapper($mappings)->write($mappings->get('User'), ['userName' => 'bjensen', 'addresses' => [['type' => 'Work', 'locality' => 'Paris']]], $user, false);

        self::assertSame([[$first], 'Work', 'Paris'], [$user->getAddresses()->getValues(), $first->getType(), $first->getLocality()]);

        self::mapper($mappings)->write($mappings->get('User'), ['userName' => 'bjensen'], $user, false);

        self::assertSame([], $user->getAddresses()->getValues());
    }

    private function mailboxes(): ResourceMapping
    {
        return self::mappings(['User' => Mailbox::class])->get('User');
    }

    private function email(Mailbox $mailbox, string $value, ?string $type, bool $primary): MailboxEmail
    {
        $email = new MailboxEmail($mailbox, $value);
        $email->type = $type;
        $email->primary = $primary;

        return $email;
    }

    /**
     * @param list<array<string, mixed>> $emails
     */
    private function write(Mailbox $mailbox, array $emails): void
    {
        $mappings = self::mappings(['User' => Mailbox::class]);
        self::mapper($mappings)->write($mappings->get('User'), ['userName' => 'mailbox', 'emails' => $emails], $mailbox, false);
    }
}
