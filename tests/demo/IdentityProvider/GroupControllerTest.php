<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\IdentityProvider;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryGroup;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Provisioner;

final class GroupControllerTest extends WebTestCase
{
    use IdentityProviderKernel;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::createSchema();
    }

    #[Test]
    #[TestDox('The list shows each group with members and SCIM id')]
    public function listsGroups(): void
    {
        $group = self::newGroup('Engineering', self::newUser('Ada', 'Lovelace'), self::newUser('Grace', 'Hopper'));
        $group->scimId = 'scim-engineering';
        self::save($group);
        self::newGroup('Sales');

        $crawler = $this->client->request('GET', '/groups');

        self::assertSame(
            ['Engineering|ada.lovelace@example.com, grace.hopper@example.com|scim-engineering|Delete', 'Sales||not provisioned|Delete'],
            $crawler->filter('tbody tr')->each(static fn ($row): string => implode('|', $row->filter('td')->each(static fn ($cell): string => trim($cell->text()))))
        );
    }

    #[Test]
    #[TestDox('The empty list says so')]
    public function listsNoGroups(): void
    {
        $this->client->request('GET', '/groups');

        self::assertSelectorTextSame('tbody', 'No groups.');
    }

    #[Test]
    #[TestDox('Creating a group with members provisions the group and its provisioned members')]
    public function createsGroup(): void
    {
        $member = self::newUser('Ada', 'Lovelace');
        self::service(Provisioner::class)->syncUser($member);
        self::newUser('Grace', 'Hopper');

        $this->client->request('GET', '/groups/new');
        $this->client->submitForm('Save and provision', ['group[displayName]' => 'Engineering', 'group[members]' => [(string) $member->id]]);

        self::assertResponseRedirects('/groups');
        $group = self::service(EntityManagerInterface::class)->getRepository(DirectoryGroup::class)->findOneBy(['displayName' => 'Engineering']);
        self::assertInstanceOf(DirectoryGroup::class, $group);
        self::assertSame([['value' => $member->scimId]], self::fakeServiceProvider()->group($group->scimId)['members']);
        $this->client->followRedirect();
        self::assertSelectorTextSame('.flash-success', 'Group "Engineering" saved. Provisioned.');
    }

    #[Test]
    #[TestDox('An invalid group is not saved')]
    public function rejectsInvalidGroup(): void
    {
        $this->client->request('GET', '/groups/new');
        $this->client->submitForm('Save and provision', ['group[displayName]' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, self::service(EntityManagerInterface::class)->getRepository(DirectoryGroup::class)->count());
    }

    #[Test]
    #[TestDox('Renaming a group provisions the new name')]
    public function editsGroup(): void
    {
        $group = self::newGroup('Engineering');
        self::service(Provisioner::class)->syncGroup($group);

        $this->client->request('GET', \sprintf('/groups/%d/edit', $group->id));
        $this->client->submitForm('Save and provision', ['group[displayName]' => 'Research']);

        self::assertResponseRedirects('/groups');
        self::assertSame('Research', self::fakeServiceProvider()->group($group->scimId)['displayName']);
    }

    #[Test]
    #[TestDox('A failed delete keeps the group and its SCIM id; the next delete retries')]
    public function keepsGroupOnFailedDelete(): void
    {
        $group = self::newGroup('Engineering');
        self::service(Provisioner::class)->syncGroup($group);
        $scimId = (string) $group->scimId;
        self::useToken('wrong-token');

        $this->client->submit($this->client->request('GET', '/groups')->selectButton('Delete')->form());
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextSame('.flash-error', \sprintf('Group "Engineering" not deleted. Provisioning failed: DELETE %s/Groups/%s answered 401: Authorization failure.', FakeServiceProvider::BASE_URL, $scimId));
        self::assertSame($scimId, self::service(EntityManagerInterface::class)->find(DirectoryGroup::class, $group->id)?->scimId);

        self::useToken(FakeServiceProvider::TOKEN);
        $this->client->submit($crawler->selectButton('Delete')->form());

        self::assertNull(self::fakeServiceProvider()->groups->get($scimId));
        self::assertSame(0, self::service(EntityManagerInterface::class)->getRepository(DirectoryGroup::class)->count());
    }

    #[Test]
    #[TestDox('Deleting an unprovisioned group sends nothing')]
    public function deletesUnprovisionedGroup(): void
    {
        self::newGroup('Engineering');

        $this->client->submit($this->client->request('GET', '/groups')->selectButton('Delete')->form());
        $this->client->followRedirect();

        self::assertSame([], self::fakeServiceProvider()->requests);
        self::assertSelectorTextSame('.flash-success', 'Group "Engineering" deleted. It was not provisioned.');
    }
}
