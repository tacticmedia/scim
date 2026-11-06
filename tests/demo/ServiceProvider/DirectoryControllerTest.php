<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\ServiceProvider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use TacticMedia\ScimDemo\ServiceProvider\Entity\Group;
use TacticMedia\ScimDemo\ServiceProvider\Entity\User;

final class DirectoryControllerTest extends WebTestCase
{
    use ServiceProviderKernel;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::createSchema();
    }

    #[Test]
    #[TestDox('The directory shows each user with manager and groups, and each group with users and nested groups')]
    public function listsUsersAndGroups(): void
    {
        $manager = new User('grace.hopper@example.com');
        $manager->setDisplayName('Grace Hopper');
        $manager->setEmail('grace.hopper@example.com');
        $manager->setDepartment('Engineering');

        $user = new User('ada.lovelace@example.com');
        $user->setActive(false);
        $user->setManager($manager);

        $platform = new Group('Platform');
        $platform->addUser($user);

        $engineering = new Group('Engineering');
        $engineering->addUser($manager);
        $engineering->addSubgroup($platform);
        array_map(self::save(...), [$manager, $user, $platform, $engineering]);
        self::entityManager()->clear();

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['|ada.lovelace@example.com||no|Grace Hopper|Platform|', 'Grace Hopper|grace.hopper@example.com|grace.hopper@example.com|yes||Engineering|Engineering'],
            $this->rows($crawler->filter('table')->eq(0)),
        );
        self::assertSame(['Engineering|Grace Hopper, Platform (group)', 'Platform|ada.lovelace@example.com'], $this->rows($crawler->filter('table')->eq(1)));
    }

    #[Test]
    #[TestDox('A user that SCIM deleted shows nowhere: not as a user, a manager or a group member')]
    public function hidesDeletedUsers(): void
    {
        $manager = new User('grace.hopper@example.com');
        $manager->setDisplayName('Grace Hopper');

        $user = new User('ada.lovelace@example.com');
        $user->setManager($manager);

        $engineering = new Group('Engineering');
        $engineering->addUser($manager);
        $engineering->addUser($user);
        array_map(self::save(...), [$manager, $user, $engineering]);
        self::entityManager()->clear();

        $this->client->request('DELETE', '/scim/v2/Users/'.$manager->getId(), server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN]);
        self::assertResponseStatusCodeSame(204);
        $crawler = $this->client->request('GET', '/');

        self::assertSame(['|ada.lovelace@example.com||yes||Engineering|'], $this->rows($crawler->filter('table')->eq(0)));
        self::assertSame(['Engineering|ada.lovelace@example.com'], $this->rows($crawler->filter('table')->eq(1)));
    }

    #[Test]
    #[TestDox('The empty directory says that the identity provider provisions the data, and shows the SCIM base URL')]
    public function listsNothing(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.note', 'The identity provider provisions this data through SCIM.');
        self::assertSelectorTextContains('.note', 'SCIM base URL: http://localhost/scim/v2');
        self::assertSelectorTextSame('table:first-of-type tbody', 'No users. Provision them from the identity provider.');
        self::assertSelectorTextSame('table:last-of-type tbody', 'No groups.');
    }

    /**
     * @return list<string>
     */
    private function rows(Crawler $table): array
    {
        return $table->filter('tbody tr')->each(static fn (Crawler $row): string => implode('|', $row->filter('td')->each(static fn (Crawler $cell): string => $cell->text())));
    }
}
