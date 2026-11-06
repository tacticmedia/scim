<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServerBundle\Command\DebugScimCommand;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Converter\NativeConverter;
use TacticMedia\ScimServerBundle\Doctrine\Orm\OrmObjectStore;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Schema\PublishedResourceTypes;
use TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\DeltaFixtures\Band;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\Device;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\PasswordUser;
use TacticMedia\ScimServerBundle\Tests\Validation\Fixtures\Member;

final class DebugScimCommandTest extends KernelTestCase
{
    #[Test]
    #[TestDox('debug:scim shows every resource type, the paths of each mapping, and passes the checks')]
    public function showsEveryResourceType(): void
    {
        $result = self::runCommand('debug:scim');
        $display = preg_replace('/ +/', ' ', $result->getDisplay());

        $this->assertCommandIsSuccessful($result);
        self::assertStringContainsString('Endpoint /Users', (string) $display);
        self::assertStringContainsString('emails[type eq "work"].value $email Slot all yes', (string) $display);
        self::assertStringContainsString('active $enabled Field eq, ne, pr yes', (string) $display);
        self::assertStringContainsString('password $password Field no no', (string) $display);
        self::assertStringContainsString('members[type eq "User"] $users ReferenceCollection value eq, ne, type, pr no', (string) $display);
        self::assertStringContainsString('groups $groups ReferenceCollection value eq, ne, pr no', (string) $display);
        self::assertStringContainsString('manager $manager Reference value eq, ne, pr no', (string) $display);
        self::assertStringContainsString('meta.created $createdAt Field eq, ne, gt, ge, lt, le, pr yes', (string) $display);
        self::assertStringContainsString('Store none: a raw ResourceRepository decides filters and sorting', (string) $display);
        self::assertSame(3, substr_count((string) $display, '[OK] The mapping checks pass.'));
    }

    #[Test]
    #[TestDox('debug:scim <name> shows one resource type, in any case; an unknown name fails')]
    public function showsOneResourceType(): void
    {
        $user = self::runCommand('debug:scim', ['name' => 'user'])->getDisplay();
        $unknown = self::runCommand('debug:scim', ['name' => 'Device']);

        self::assertStringContainsString('urn:ietf:params:scim:schemas:extension:enterprise:2.0:User', $user);
        self::assertStringNotContainsString('Printer', $user);
        $this->assertCommandFailed($unknown);
        self::assertStringContainsString('No resource type is named "Device". Resource types: Badge, Group, User, Printer.', (string) preg_replace('/\s+/', ' ', $unknown->getDisplay()));
    }

    #[Test]
    #[TestDox('debug:scim shows the validation groups and the member deltas of each mapping')]
    public function showsValidationAndDeltas(): void
    {
        $group = (string) preg_replace('/ +/', ' ', self::runCommand('debug:scim', ['name' => 'Group'])->getDisplay());
        $user = (string) preg_replace('/ +/', ' ', self::runCommand('debug:scim', ['name' => 'User'])->getDisplay());
        $tester = new CommandTester($this->command(['User' => Member::class, 'Group' => Band::class], new ServiceLocator([])));
        $tester->execute([]);

        $custom = (string) preg_replace('/ +/', ' ', $tester->getDisplay());

        self::assertStringContainsString(' Validation off ', $group);
        self::assertStringContainsString(' Member deltas members ', $group);
        self::assertStringContainsString(' Member deltas none ', $user);
        self::assertStringContainsString(' Validation groups: scim ', $custom);
        self::assertStringContainsString(' Member deltas off: deltas: false ', $custom);
    }

    #[Test]
    #[TestDox('Only a string id shows gt, ge, lt, le and sorting')]
    public function onlyStringIdOrders(): void
    {
        $display = (string) preg_replace('/ +/', ' ', self::runCommand('debug:scim')->getDisplay());
        $tester = new CommandTester($this->command(['User' => PasswordUser::class], new ServiceLocator([])));
        $tester->execute([]);

        self::assertSame(3, substr_count($display, ' id $id Field eq, ne, pr no '));
        self::assertStringContainsString(' id $id Field all yes ', (string) preg_replace('/ +/', ' ', $tester->getDisplay()));
    }

    #[Test]
    #[TestDox('Missing store, factory and converter services fail the checks')]
    public function missingServicesFail(): void
    {
        $tester = new CommandTester($this->command(['Device' => Device::class], new ServiceLocator([])));

        self::assertSame(1, $tester->execute([]));
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString(Device::class.': Doctrine ORM is not available. Install doctrine/orm and doctrine/doctrine-bundle, or set #[ScimResource(store: ...)].', $display);
        self::assertStringContainsString(Device::class.': factory: TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\DeviceFactory is not a service. Register it as a service with autoconfigure: true.', $display);
        self::assertStringContainsString(Device::class.': converter: TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\TagConverter is not a service.', $display);
        self::assertStringContainsString('label() Computed no no', $display);
    }

    #[Test]
    #[TestDox('The Doctrine checks fail for a class that Doctrine ORM does not manage')]
    public function doctrineChecksFail(): void
    {
        $mappings = new MappingRegistry(new AttributeLoader(), ['Device' => Device::class]);
        $store = new OrmObjectStore(self::getContainer()->get('doctrine'), $mappings, new ServiceLocator([IdConverter::class => static fn (): IdConverter => new IdConverter(), NativeConverter::class => static fn (): NativeConverter => new NativeConverter()]));
        $tester = new CommandTester($this->command(['Device' => Device::class], new ServiceLocator([OrmObjectStore::class => static fn (): OrmObjectStore => $store]), $mappings));

        self::assertSame(1, $tester->execute(['name' => 'Device']));
        self::assertStringContainsString(Device::class.': Doctrine ORM does not manage the class. Map it with #[ORM\Entity], or set #[ScimResource(store: ...)].', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    #[Test]
    #[TestDox('Without resource types, debug:scim fails with the hint about excluded entity directories and resources')]
    public function noResourceTypesFail(): void
    {
        $tester = new CommandTester($this->command([], new ServiceLocator([])));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString("Remove exclude: '../src/Entity/' from services.yaml, or list the classes in tacticmedia_scim_server.resources.", (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    /**
     * @param array<string, class-string> $classes
     * @param ServiceLocator<mixed>       $stores
     */
    private function command(array $classes, ServiceLocator $stores, ?MappingRegistry $mappings = null): DebugScimCommand
    {
        $mappings ??= new MappingRegistry(new AttributeLoader(), $classes);

        return new DebugScimCommand($mappings, new PublishedResourceTypes($mappings), [], $stores, new ServiceLocator([]), new ServiceLocator([]));
    }
}
