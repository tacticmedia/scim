<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\IdentityProvider;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryGroup;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Dialect;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Provisioner;

final class UserControllerTest extends WebTestCase
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
    #[TestDox('The list shows each user with manager, active state and SCIM id')]
    public function listsUsers(): void
    {
        $manager = self::newUser('Grace', 'Hopper');
        $manager->scimId = 'scim-grace';
        $manager->active = false;
        self::save($manager);
        self::newUser('Ada', 'Lovelace', $manager);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['grace.hopper@example.com|Grace Hopper|Engineering||no|scim-grace|Enable Delete', 'ada.lovelace@example.com|Ada Lovelace|Engineering|grace.hopper@example.com|yes|not provisioned|Disable Delete'],
            $crawler->filter('tbody tr')->each(static fn ($row): string => implode('|', $row->filter('td')->each(static fn ($cell): string => trim((string) preg_replace('/\s+/', ' ', $cell->text())))))
        );
    }

    #[Test]
    #[TestDox('The empty list points to idp:seed')]
    public function listsNoUsers(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorTextContains('tbody', 'No users. Run idp:seed or create one.');
    }

    #[Test]
    #[TestDox('Creating a user saves it, provisions it and reports both')]
    public function createsUser(): void
    {
        $this->client->request('GET', '/users/new');
        $this->client->submitForm('Save and provision', [
            'user[userName]' => 'ada.lovelace@example.com',
            'user[givenName]' => 'Ada',
            'user[familyName]' => 'Lovelace',
            'user[displayName]' => 'Ada Lovelace',
            'user[workEmail]' => 'ada.lovelace@example.com',
            'user[active]' => true,
        ]);

        self::assertResponseRedirects('/');
        $user = self::service(EntityManagerInterface::class)->getRepository(DirectoryUser::class)->findOneBy(['userName' => 'ada.lovelace@example.com']);
        self::assertInstanceOf(DirectoryUser::class, $user);
        self::assertSame('Ada Lovelace', self::fakeServiceProvider()->user($user->scimId)['displayName']);
        $this->client->followRedirect();
        self::assertSelectorTextSame('.flash-success', 'User "ada.lovelace@example.com" saved. Provisioned.');
    }

    #[Test]
    #[TestDox('Editing a user provisions the change')]
    public function editsUser(): void
    {
        $user = self::newUser('Ada', 'Lovelace');
        self::service(Provisioner::class)->syncUser($user);

        $this->client->request('GET', \sprintf('/users/%d/edit', $user->id));
        $this->client->submitForm('Save and provision', ['user[department]' => 'Research']);

        self::assertResponseRedirects('/');
        self::assertSame('PATCH /Users/'.$user->scimId, array_last(self::fakeServiceProvider()->requests)['line'] ?? null);
        self::assertSame('Research', self::fakeServiceProvider()->user($user->scimId)['urn:ietf:params:scim:schemas:extension:enterprise:2.0:User']['department'] ?? null);
    }

    #[Test]
    #[TestDox('Disable and enable provision the active state')]
    public function togglesActiveState(): void
    {
        $user = self::newUser('Ada', 'Lovelace');
        self::service(Provisioner::class)->syncUser($user);
        $scimId = (string) $user->scimId;

        $this->client->submit($this->client->request('GET', '/')->selectButton('Disable')->form());
        $disabled = self::fakeServiceProvider()->user($scimId)['active'];
        $this->client->followRedirect();
        self::assertSelectorTextSame('.flash-success', 'User "ada.lovelace@example.com" disabled. Provisioned.');
        $this->client->submit($this->client->request('GET', '/')->selectButton('Enable')->form());

        self::assertFalse($disabled);
        self::assertTrue(self::fakeServiceProvider()->user($scimId)['active']);
    }

    #[Test]
    #[TestDox('Deleting a user removes it from its groups and as manager')]
    public function deletesUserReferences(): void
    {
        $manager = self::newUser('Grace', 'Hopper');
        $user = self::newUser('Ada', 'Lovelace', $manager);
        $group = self::newGroup('Engineering', $manager, $user);

        $this->client->submit($this->client->request('GET', '/')->selectButton('Delete')->form());

        $entityManager = self::service(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertNull($entityManager->find(DirectoryUser::class, $user->id)?->manager);
        self::assertSame(['ada.lovelace@example.com'], $entityManager->find(DirectoryGroup::class, $group->id)?->members->map(static fn (DirectoryUser $member): string => $member->userName)->getValues());
    }

    #[Test]
    #[TestDox('Deleting an unprovisioned user sends nothing')]
    public function deletesUnprovisionedUser(): void
    {
        self::newUser('Ada', 'Lovelace');

        $this->client->submit($this->client->request('GET', '/')->selectButton('Delete')->form());
        $this->client->followRedirect();

        self::assertSame([], self::fakeServiceProvider()->requests);
        self::assertSelectorTextSame('.flash-success', 'User "ada.lovelace@example.com" deleted. It was not provisioned.');
    }

    #[Test]
    #[TestDox('A failed delete keeps the user and its SCIM id; the next delete retries')]
    public function keepsUserOnFailedDelete(): void
    {
        $user = self::newUser('Ada', 'Lovelace');
        self::service(Provisioner::class)->syncUser($user);
        $scimId = (string) $user->scimId;
        self::useToken('wrong-token');

        $this->client->submit($this->client->request('GET', '/')->selectButton('Delete')->form());
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextSame('.flash-error', \sprintf('User "ada.lovelace@example.com" not deleted. Provisioning failed: DELETE %s/Users/%s answered 401: Authorization failure.', FakeServiceProvider::BASE_URL, $scimId));
        self::assertSame($scimId, self::service(EntityManagerInterface::class)->find(DirectoryUser::class, $user->id)?->scimId);
        self::assertTrue(self::fakeServiceProvider()->user($scimId)['active']);

        self::useToken(FakeServiceProvider::TOKEN);
        $this->client->submit($crawler->selectButton('Delete')->form());

        self::assertNull(self::fakeServiceProvider()->users->get($scimId));
        self::assertSame(0, self::service(EntityManagerInterface::class)->getRepository(DirectoryUser::class)->count());
    }

    #[Test]
    #[TestDox('Deleting a user that the service provider no longer has (404) deletes it')]
    public function deletesUserGoneAtServiceProvider(): void
    {
        $user = self::newUser('Ada', 'Lovelace');
        $user->scimId = 'vanished';
        self::save($user);

        $this->client->submit($this->client->request('GET', '/')->selectButton('Delete')->form());
        $this->client->followRedirect();

        self::assertSame([['DELETE /Users/vanished', 404]], array_map(static fn (array $request): array => [$request['line'], $request['status']], self::fakeServiceProvider()->requests));
        self::assertSelectorTextSame('.flash-success', 'User "ada.lovelace@example.com" deleted. Provisioned.');
        self::assertSame(0, self::service(EntityManagerInterface::class)->getRepository(DirectoryUser::class)->count());
    }

    #[Test]
    #[TestDox('A userName that differs from another only in case is rejected')]
    public function rejectsUserNameInOtherCase(): void
    {
        self::newUser('Ada', 'Lovelace');

        $this->client->request('GET', '/users/new');
        $this->client->submitForm('Save and provision', [
            'user[userName]' => 'Ada.Lovelace@example.com',
            'user[givenName]' => 'Ada',
            'user[familyName]' => 'Lovelace',
            'user[displayName]' => 'Ada Lovelace',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name=user]', 'This value is already used.');
        self::assertSame(1, self::service(EntityManagerInterface::class)->getRepository(DirectoryUser::class)->count());
    }

    #[Test]
    #[TestDox('A provisioning failure keeps the local change and reports the SCIM error')]
    public function keepsLocalChangeOnFailure(): void
    {
        self::useSettings(Dialect::EntraDefault, 'wrong-token');
        $user = self::newUser('Ada', 'Lovelace');

        $this->client->submit($this->client->request('GET', '/')->selectButton('Disable')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash-error', 'User "ada.lovelace@example.com" disabled. Provisioning failed: GET http://127.0.0.1:8001/scim/v2/Users?filter=');
        self::assertSelectorTextContains('.flash-error', 'answered 401: Authorization failure.');
        self::assertFalse(self::service(EntityManagerInterface::class)->find(DirectoryUser::class, $user->id)?->active);
    }

    #[Test]
    #[TestDox('A request without a valid CSRF token is forbidden')]
    public function rejectsInvalidToken(): void
    {
        $user = self::newUser('Ada', 'Lovelace');

        $this->client->request('POST', \sprintf('/users/%d/delete', $user->id), ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
    }
}
