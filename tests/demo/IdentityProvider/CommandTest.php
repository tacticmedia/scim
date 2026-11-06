<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\IdentityProvider;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryGroup;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Dialect;

final class CommandTest extends KernelTestCase
{
    use IdentityProviderKernel;

    protected function setUp(): void
    {
        self::bootKernel();
        self::createSchema();
    }

    #[Test]
    #[TestDox('idp:seed creates 6 users in a manager chain and 2 groups, one a subset of the other')]
    public function seedsDirectory(): void
    {
        $tester = $this->command('idp:seed');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $entityManager = self::service(EntityManagerInterface::class);
        $entityManager->clear();

        $managers = [];

        foreach ($entityManager->getRepository(DirectoryUser::class)->findBy([], ['id' => 'ASC']) as $user) {
            $managers[$user->userName] = $user->manager?->userName;
        }

        self::assertSame([
            'ada.lovelace@example.com' => null,
            'grace.hopper@example.com' => 'ada.lovelace@example.com',
            'linus.torvalds@example.com' => 'grace.hopper@example.com',
            'margaret.hamilton@example.com' => 'linus.torvalds@example.com',
            'dennis.ritchie@example.com' => 'linus.torvalds@example.com',
            'mary.jackson@example.com' => 'ada.lovelace@example.com',
        ], $managers);
        $members = [];

        foreach ($entityManager->getRepository(DirectoryGroup::class)->findBy([], ['id' => 'ASC']) as $group) {
            $members[$group->displayName] = $group->members->map(static fn (DirectoryUser $member): string => $member->userName)->getValues();
        }

        self::assertSame([
            'Engineering' => ['grace.hopper@example.com', 'linus.torvalds@example.com', 'margaret.hamilton@example.com', 'dennis.ritchie@example.com'],
            'Platform Team' => ['linus.torvalds@example.com', 'margaret.hamilton@example.com'],
        ], $members);
        self::assertStringContainsString('Created 6 users and 2 groups.', $tester->getDisplay());
    }

    #[Test]
    #[TestDox('idp:seed refuses a directory that is not empty')]
    public function refusesToSeedTwice(): void
    {
        self::newUser('Ada', 'Lovelace');

        $tester = $this->command('idp:seed');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('The directory is not empty.', $tester->getDisplay());
    }

    #[Test]
    #[TestDox('idp:provision prints each request line with its status')]
    public function provisionsEverything(): void
    {
        self::newUser('Ada', 'Lovelace');

        $tester = $this->command('idp:provision');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringStartsWith(implode("\n", [
            'GET http://127.0.0.1:8001/scim/v2/Users?filter=userName%20eq%20%22ada.lovelace%40example.com%22 200',
            'GET http://127.0.0.1:8001/scim/v2/Users?filter=externalId%20eq%20%22ada.lovelace%22 200',
            'POST http://127.0.0.1:8001/scim/v2/Users 201',
        ]), $tester->getDisplay());
        self::assertStringContainsString('Every user and group is provisioned.', $tester->getDisplay());
    }

    #[Test]
    #[TestDox('idp:provision fails with the failure messages')]
    public function reportsFailures(): void
    {
        self::useSettings(Dialect::Okta, 'wrong-token');
        self::newUser('Ada', 'Lovelace');

        $tester = $this->command('idp:provision');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('GET http://127.0.0.1:8001/scim/v2/Users?filter=userName%20eq%20%22ada.lovelace%40example.com%22&startIndex=1&count=100 401', $tester->getDisplay());
        self::assertStringContainsString('Authorization failure.', $tester->getDisplay());
    }

    #[Test]
    #[TestDox('idp:import prints each page request and the users of the service provider')]
    public function importsUsers(): void
    {
        self::useSettings(Dialect::Keycloak);
        self::fakeServiceProvider()->cursors = false;

        foreach (['ada' => true, 'grace' => null, 'linus' => false] as $userName => $active) {
            self::fakeServiceProvider()->users->create(array_filter(['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'], 'userName' => $userName, 'displayName' => ucfirst($userName), 'active' => $active], static fn (mixed $value): bool => null !== $value));
        }

        $tester = $this->command('idp:import');

        self::assertSame(Command::SUCCESS, $tester->execute(['--count' => '2']));
        $display = $tester->getDisplay();
        self::assertStringStartsWith("GET http://127.0.0.1:8001/scim/v2/Users?cursor&count=2 200\nGET http://127.0.0.1:8001/scim/v2/Users?startIndex=3&count=2 200\n", $display);
        self::assertMatchesRegularExpression('~ ada +Ada +yes +\n.* grace +Grace +\n.* linus +Linus +no +\n~', $display);
        self::assertStringContainsString('Imported 3 users.', $display);
    }

    #[Test]
    #[TestDox('idp:import fails with the failure message')]
    public function reportsImportFailure(): void
    {
        self::useSettings(Dialect::Okta, 'wrong-token');

        $tester = $this->command('idp:import');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('GET http://127.0.0.1:8001/scim/v2/Users?startIndex=1&count=100 401', $tester->getDisplay());
        self::assertStringContainsString('Authorization failure.', $tester->getDisplay());
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester(new Application(self::$kernel ?? throw new \LogicException('Boot the kernel first.'))->find($name));
    }
}
