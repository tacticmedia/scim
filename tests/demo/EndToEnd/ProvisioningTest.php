<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\EndToEnd;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryGroup;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;
use TacticMedia\ScimDemo\IdentityProvider\Entity\ProvisioningLogEntry;
use TacticMedia\ScimDemo\IdentityProvider\Entity\ProvisioningSettings;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Dialect;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Provisioner;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\UserImporter;
use TacticMedia\ScimDemo\IdentityProvider\Repository\ProvisioningLogEntryRepository;
use TacticMedia\ScimDemo\Tests\IdentityProvider\IdentityProviderKernel;

/**
 * The IdP kernel in the environment "e2e", which keeps the real HTTP client, provisions the SP server. Each step
 * changes the directory as the IdP UI does, provisions the change and compares the SP database and the SCIM
 * responses with the expected state.
 *
 * @phpstan-import-type State from ServiceProviderServer
 */
final class ProvisioningTest extends KernelTestCase
{
    use IdentityProviderKernel;
    private const string TOKEN = 'end-to-end-token';
    private const string ADA = 'ada.lovelace@example.com';
    private const string GRACE = 'grace.hopper@example.com';
    private const string LINUS = 'linus.torvalds@example.com';
    private const string MARGARET = 'margaret.hamilton@example.com';
    private const string DENNIS = 'dennis.ritchie@example.com';
    private const string MARY = 'mary.jackson@example.com';

    private ServiceProviderServer $serviceProvider;

    private ?string $identityProviderDatabase = null;

    /** @var State */
    private array $expected = ['users' => [], 'groups' => []];

    private Dialect $dialect = Dialect::EntraDefault;

    /**
     * @return iterable<string, array{Dialect}>
     */
    public static function dialects(): iterable
    {
        foreach (Dialect::cases() as $dialect) {
            yield $dialect->value => [$dialect];
        }
    }

    protected function setUp(): void
    {
        $this->serviceProvider = new ServiceProviderServer(self::TOKEN);
        $this->serviceProvider->start();

        $kernel = self::bootKernel(['environment' => 'e2e']);
        $this->identityProviderDatabase = \sprintf('%s/identity-provider-%d.sqlite', $kernel->getCacheDir(), getmypid());
        self::createSchema();
    }

    protected function tearDown(): void
    {
        $this->serviceProvider->stop();
        parent::tearDown();

        if (null !== $this->identityProviderDatabase && is_file($this->identityProviderDatabase)) {
            unlink($this->identityProviderDatabase);
        }
    }

    #[Test]
    #[DataProvider('dialects')]
    #[TestDox('$dialect: each directory change reaches the service provider, and the import pages through its users')]
    public function provisionsDirectoryChanges(Dialect $dialect): void
    {
        $this->seedAndProvisionEverything($dialect);
        $this->renameUser();
        $this->disableUser();
        $this->changeManager();
        $this->changeGroupMembers();
        $this->renameGroup();
        $this->deleteUser($dialect);
        $this->deleteGroup();
        $this->provisionEverythingAgain();
        $this->importUsers();
    }

    private function seedAndProvisionEverything(Dialect $dialect): void
    {
        $this->dialect = $dialect;
        self::save(new ProvisioningSettings($this->serviceProvider->scimBaseUrl, self::TOKEN, $dialect));
        $seed = new CommandTester(new Application(self::$kernel ?? throw new \LogicException('Boot the kernel first.'))->find('idp:seed'));
        $seed->execute([]);
        $seed->assertCommandIsSuccessful();

        $this->provisionEverything();

        $this->expected = [
            'users' => [
                self::ADA => $this->seededUser('Ada', 'Lovelace', 'Executive', 'E001', null),
                self::GRACE => $this->seededUser('Grace', 'Hopper', 'Engineering', 'E002', self::ADA),
                self::LINUS => $this->seededUser('Linus', 'Torvalds', 'Engineering', 'E003', self::GRACE),
                self::MARGARET => $this->seededUser('Margaret', 'Hamilton', 'Engineering', 'E004', self::LINUS),
                self::DENNIS => $this->seededUser('Dennis', 'Ritchie', 'Engineering', 'E005', self::LINUS),
                self::MARY => $this->seededUser('Mary', 'Jackson', 'Sales', 'E006', self::ADA),
            ],
            'groups' => [
                'Engineering' => [self::GRACE, self::LINUS, self::MARGARET, self::DENNIS],
                'Platform Team' => [self::LINUS, self::MARGARET],
            ],
        ];
        $this->assertServiceProvider('the first full cycle');
    }

    private function renameUser(): void
    {
        $margaret = $this->directoryUser(self::MARGARET);
        $margaret->familyName = 'Heafield';
        $margaret->displayName = 'Margaret Heafield';
        $this->provision(static fn (Provisioner $provisioner) => $provisioner->syncUser($margaret));

        $this->expected['users'][self::MARGARET]['displayName'] = 'Margaret Heafield';
        $this->expected['users'][self::MARGARET]['name'] = ['Margaret', 'Heafield'];
        $this->assertServiceProvider('the user rename');
    }

    private function disableUser(): void
    {
        $dennis = $this->directoryUser(self::DENNIS);
        $dennis->active = false;
        $this->provision(static fn (Provisioner $provisioner) => $provisioner->syncUser($dennis));

        $this->expected['users'][self::DENNIS]['active'] = false;
        $this->assertServiceProvider('the user deactivation');
    }

    private function changeManager(): void
    {
        $margaret = $this->directoryUser(self::MARGARET);
        $margaret->manager = $this->directoryUser(self::GRACE);
        $this->provision(static fn (Provisioner $provisioner) => $provisioner->syncUser($margaret));

        $this->expected['users'][self::MARGARET]['manager'] = self::GRACE;
        $this->assertServiceProvider('the manager change');
    }

    private function changeGroupMembers(): void
    {
        $platformTeam = $this->directoryGroup('Platform Team');
        $platformTeam->members->removeElement($this->directoryUser(self::MARGARET));
        $platformTeam->members->add($this->directoryUser(self::MARY));
        $this->provision(static fn (Provisioner $provisioner) => $provisioner->syncGroup($platformTeam));

        $this->expected['groups']['Platform Team'] = [self::LINUS, self::MARY];
        $this->assertServiceProvider('the member removal and addition');
    }

    private function renameGroup(): void
    {
        $platformTeam = $this->directoryGroup('Platform Team');
        $platformTeam->displayName = 'Platform Engineering';
        $this->provision(static fn (Provisioner $provisioner) => $provisioner->syncGroup($platformTeam));

        $this->expected['groups']['Platform Engineering'] = $this->expected['groups']['Platform Team'];
        unset($this->expected['groups']['Platform Team']);
        $this->assertServiceProvider('the group rename');
    }

    /**
     * Entra deletes the user, so the SP clears the manager of the reports and the memberships. Okta and Keycloak only
     * deactivate.
     */
    private function deleteUser(Dialect $dialect): void
    {
        $linus = $this->directoryUser(self::LINUS);
        $scimId = (string) $linus->scimId;
        self::service(EntityManagerInterface::class)->remove($linus);
        $this->provision(static fn (Provisioner $provisioner) => $provisioner->deleteUser($scimId));

        if (\in_array($dialect, [Dialect::Okta, Dialect::Keycloak], true)) {
            $this->expected['users'][self::LINUS]['active'] = false;
        } else {
            unset($this->expected['users'][self::LINUS]);
            $this->expected['users'][self::DENNIS]['manager'] = null;
            $this->expected['groups']['Engineering'] = [self::GRACE, self::MARGARET, self::DENNIS];
            $this->expected['groups']['Platform Engineering'] = [self::MARY];
        }

        $this->assertServiceProvider('the user deletion');
    }

    private function deleteGroup(): void
    {
        $platformEngineering = $this->directoryGroup('Platform Engineering');
        $scimId = (string) $platformEngineering->scimId;
        self::service(EntityManagerInterface::class)->remove($platformEngineering);
        $this->provision(static fn (Provisioner $provisioner) => $provisioner->deleteGroup($scimId));

        unset($this->expected['groups']['Platform Engineering']);
        $this->assertServiceProvider('the group deletion');
    }

    /**
     * Okta and Keycloak now remove the deleted user from its group, Okta also as manager of its report. Entra did both
     * at the deletion.
     */
    private function provisionEverythingAgain(): void
    {
        $this->provisionEverything();

        $this->expected['users'][self::DENNIS]['manager'] = null;
        $this->expected['groups']['Engineering'] = [self::GRACE, self::MARGARET, self::DENNIS];
        $this->assertServiceProvider('the second full cycle');
    }

    /**
     * Pages of 2 users: Keycloak follows nextCursor, the other dialects increase startIndex.
     */
    private function importUsers(): void
    {
        $logEntryRepository = self::service(ProvisioningLogEntryRepository::class);
        $lastLogEntryId = $logEntryRepository->lastId();

        $users = self::service(UserImporter::class)->import(2);

        self::assertEqualsCanonicalizing(array_keys($this->expected['users']), array_column($users, 'userName'));
        $pageUrls = array_map(static fn (ProvisioningLogEntry $entry): string => $entry->url, $logEntryRepository->after($lastLogEntryId));
        self::assertCount((int) ceil(\count($users) / 2), $pageUrls);
        self::assertStringEndsWith(Dialect::Keycloak === $this->dialect ? '/Users?cursor&count=2' : '/Users?startIndex=1&count=2', $pageUrls[0]);
        self::assertStringContainsString(Dialect::Keycloak === $this->dialect ? '/Users?cursor=' : '/Users?startIndex=3&count=2', $pageUrls[1]);
    }

    private function provisionEverything(): void
    {
        self::assertSame([], self::service(Provisioner::class)->syncAll());
        self::service(EntityManagerInterface::class)->clear();
    }

    /**
     * Saves the directory, then provisions, as the IdP controllers do. The cleared entity manager makes the next step
     * read the directory as a new request does.
     *
     * @param \Closure(Provisioner): void $provisioning
     */
    private function provision(\Closure $provisioning): void
    {
        $entityManager = self::service(EntityManagerInterface::class);
        $entityManager->flush();
        $provisioning(self::service(Provisioner::class));
        $entityManager->clear();
    }

    private function assertServiceProvider(string $afterStep): void
    {
        $users = Dialect::Keycloak === $this->dialect
            ? array_map(static fn (array $user): array => [...$user, 'manager' => null], $this->expected['users'])
            : $this->expected['users'];
        $expected = ServiceProviderServer::state($users, $this->expected['groups']);

        self::assertSame($expected, $this->serviceProvider->databaseState(), 'SP database after '.$afterStep);
        self::assertSame($expected, $this->serviceProvider->scimState(), 'SCIM responses after '.$afterStep);
    }

    private function directoryUser(string $userName): DirectoryUser
    {
        return self::service(EntityManagerInterface::class)->getRepository(DirectoryUser::class)->findOneBy(['userName' => $userName]) ?? self::fail('No IdP user '.$userName);
    }

    private function directoryGroup(string $displayName): DirectoryGroup
    {
        return self::service(EntityManagerInterface::class)->getRepository(DirectoryGroup::class)->findOneBy(['displayName' => $displayName]) ?? self::fail('No IdP group '.$displayName);
    }

    /**
     * @return array<string, mixed>
     */
    private function seededUser(string $givenName, string $familyName, string $department, string $employeeNumber, ?string $manager): array
    {
        return [
            'displayName' => $givenName.' '.$familyName,
            'name' => [$givenName, $familyName],
            'email' => strtolower($givenName.'.'.$familyName).'@example.com',
            'department' => $department,
            'employeeNumber' => $employeeNumber,
            'manager' => $manager,
            'active' => true,
        ];
    }
}
