<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Object;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServerBundle\Mapping\MappingRegistry;
use TacticMedia\ScimServerBundle\Object\Instantiator;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\Device;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\DeviceFactory;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\Account;

final class InstantiatorTest extends KernelTestCase
{
    use CreatesDocumentMapper;

    #[Test]
    #[TestDox('An optional parameter without value is omitted; a default: value is passed')]
    public function optionalParametersUseTheirDefaults(): void
    {
        $mappings = self::mappings(['User' => Account::class]);

        $account = $this->instantiator($mappings)->create($mappings->get('User'), ['userName' => 'jdoe', Account::EXTENSION => ['hiredAt' => '2026-11-05T10:00:00Z']]);

        self::assertInstanceOf(Account::class, $account);
        self::assertSame(['jdoe', 'Anonymous', 'nick', '2026-11-05', 'en'], [$account->userName, $account->displayName, $account->nickName, $account->hiredAt?->format('Y-m-d'), $account->locale]);
    }

    #[Test]
    #[TestDox('A constructor argument that does not convert is 400 invalidValue')]
    public function invalidArgumentFails(): void
    {
        $mappings = self::mappings(['User' => Account::class]);

        $this->expectExceptionObject(ScimException::invalidValue('Attribute "urn:acme:scim:schemas:extension:account:1.0:User:hiredAt": must be an xsd:dateTime.'));

        $this->instantiator($mappings)->create($mappings->get('User'), ['userName' => 'jdoe', Account::EXTENSION => ['hiredAt' => 'yesterday']]);
    }

    #[Test]
    #[TestDox('The factory of the mapping creates the object from the document')]
    public function factoryCreatesTheObject(): void
    {
        $mappings = self::mappings(['Device' => Device::class]);

        $device = $this->instantiator($mappings)->create($mappings->get('Device'), ['serialNumber' => 'SN9']);

        self::assertInstanceOf(Device::class, $device);
        self::assertSame('SN9', $device->serialNumber);
    }

    #[Test]
    #[TestDox('A factory service that is not an ObjectFactory fails')]
    public function wrongFactoryServiceFails(): void
    {
        $mappings = self::mappings(['Device' => Device::class]);
        $instantiator = new Instantiator(new ServiceLocator([DeviceFactory::class => static fn (): \stdClass => new \stdClass()]), self::mapper($mappings));

        $this->expectExceptionObject(new \LogicException(\sprintf('The factory service %s does not implement TacticMedia\ScimServerBundle\Object\ObjectFactory.', DeviceFactory::class)));

        $instantiator->create($mappings->get('Device'), []);
    }

    private function instantiator(MappingRegistry $mappings): Instantiator
    {
        return new Instantiator(new ServiceLocator([DeviceFactory::class => static fn (): DeviceFactory => new DeviceFactory()]), self::mapper($mappings));
    }
}
