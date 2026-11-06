<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\TacticMediaScimServerBundle;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Repository\PrinterRepository;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\Device;

/**
 * Compiles a container with the bundle, the given classes and the me options.
 */
final class MeConfigurationCheckTest extends KernelTestCase
{
    private const array USERS = [User::class, Group::class];

    /**
     * @param array<string, string> $me
     * @param list<class-string>    $classes
     */
    #[Test]
    #[DataProvider('lookups')]
    #[TestDox('me builds with $_dataName')]
    public function lookupsBuild(array $me, array $classes): void
    {
        self::assertTrue($this->compile($me, $classes)->isCompiled());
    }

    /**
     * @return iterable<string, array{array<string, string>, list<class-string>}>
     */
    public static function lookups(): iterable
    {
        yield 'the defaults' => [[], self::USERS];
        yield 'id in any case' => [['attribute' => 'ID'], self::USERS];
        yield 'a type name in any case' => [['resource_type' => 'user'], self::USERS];
        yield 'a Slot' => [['attribute' => 'emails.value'], self::USERS];
        yield 'a Reference' => [['attribute' => 'manager'], self::USERS];
        yield 'a raw type' => [['resource_type' => 'Printer', 'attribute' => 'serialNumber'], [PrinterRepository::class]];
    }

    #[Test]
    #[TestDox('An unknown me.resource_type fails the build with the resource type names')]
    public function unknownResourceTypeFails(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains('tacticmedia_scim_server.me.resource_type: no resource type is named "Users". Resource types: User, Group, Printer. Set one of them, or set me.resolver.');

        $this->compile(['resource_type' => 'Users'], [...self::USERS, PrinterRepository::class]);
    }

    /**
     * @param array<string, string> $me
     * @param list<class-string>    $classes
     */
    #[Test]
    #[DataProvider('unfilterable')]
    #[TestDox('me.attribute fails the build for $_dataName')]
    public function unfilterableAttributeFails(array $me, array $classes, string $error): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains('tacticmedia_scim_server.me.attribute: '.$error.' Set id or an attribute that supports eq, or set me.resolver.');

        $this->compile($me, $classes);
    }

    /**
     * @return iterable<string, array{array<string, string>, list<class-string>, string}>
     */
    public static function unfilterable(): iterable
    {
        yield 'no attribute path' => [['attribute' => 'user name'], self::USERS, '"user name" cannot select a User: "user name" is not an attribute path.'];
        yield 'an unknown attribute' => [['attribute' => 'login'], self::USERS, '"login" cannot select a User: The attribute \'login\' is not defined for the resource type User.'];
        yield 'a writeOnly attribute' => [['attribute' => 'password'], self::USERS, '"password" cannot select a User: The attribute \'urn:ietf:params:scim:schemas:core:2.0:User:password\' is never returned and cannot be used in a filter.'];
        yield 'an integer attribute' => [['resource_type' => 'Device', 'attribute' => 'location.floor'], [Device::class], '"location.floor" cannot select a Device: The value \'<user identifier>\' does not fit the integer attribute \'urn:acme:scim:schemas:core:1.0:Device:location.floor\'.'];
        yield 'a Computed attribute' => [['resource_type' => 'Device', 'attribute' => 'label'], [Device::class], '"label" cannot select a Device: The attribute \'urn:acme:scim:schemas:core:1.0:Device:label\' cannot be used in a filter.'];
        yield 'an undeclared raw attribute' => [['resource_type' => 'Printer', 'attribute' => 'model'], [PrinterRepository::class], '"model" cannot select a Printer: The attribute \'model\' is not defined for the resource type Printer.'];
    }

    #[Test]
    #[TestDox('me.resolver replaces the checks')]
    public function resolverSkipsTheChecks(): void
    {
        self::assertTrue($this->compile(['resolver' => 'app.me_resolver', 'resource_type' => 'Users', 'attribute' => 'password'], self::USERS)->isCompiled());
    }

    /**
     * @param array<string, string> $me
     * @param list<class-string>    $classes
     */
    private function compile(array $me, array $classes): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $bundle = new TacticMediaScimServerBundle();
        $bundle->build($container);

        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), ['me' => ['enabled' => true, ...$me]]);
        $container->register('security.token_storage', TokenStorage::class);
        $container->register('app.me_resolver', \stdClass::class);

        foreach ($classes as $class) {
            $container->register($class, $class)->setAutoconfigured(true);
        }

        $container->compile(true);

        return $container;
    }
}
