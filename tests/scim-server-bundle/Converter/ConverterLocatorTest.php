<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Converter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use TacticMedia\ScimServerBundle\Converter\HashPassword;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Converter\NativeConverter;
use TacticMedia\ScimServerBundle\Converter\VersionConverter;
use TacticMedia\ScimServerBundle\TacticMediaScimServerBundle;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\TagConverter;

final class ConverterLocatorTest extends KernelTestCase
{
    #[Test]
    #[TestDox('The converter locator serves the bundle converters and autoconfigured application converters by class name')]
    public function locatorServesConvertersByClass(): void
    {
        $locator = $this->locator($this->container(withHasher: true));

        foreach ([NativeConverter::class, IdConverter::class, VersionConverter::class, HashPassword::class, TagConverter::class] as $class) {
            self::assertInstanceOf($class, $this->service($locator, $class));
        }
    }

    #[Test]
    #[TestDox('Without a password hasher service the container builds, and HashPassword fails on first use')]
    public function hashPasswordNeedsAHasher(): void
    {
        $locator = $this->locator($this->container(withHasher: false));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('"tacticmedia_scim_server.converter.hash_password": it references interface "%s" but no such service exists.', UserPasswordHasherInterface::class));

        $this->service($locator, HashPassword::class);
    }

    #[Test]
    #[TestDox('The test kernel container with the converter services is valid')]
    public function kernelContainerIsValid(): void
    {
        $this->assertCommandIsSuccessful(self::runCommand('lint:container'));
    }

    private function container(bool $withHasher): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $bundle = new TacticMediaScimServerBundle();
        $bundle->build($container);

        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias());
        $container->register(TagConverter::class, TagConverter::class)->setAutoconfigured(true);
        $container->setAlias('test.converter_locator', 'tacticmedia_scim_server.converter_locator')->setPublic(true);

        if ($withHasher) {
            $container->register(UserPasswordHasherInterface::class, UserPasswordHasher::class)
                ->setArguments([new Definition(PasswordHasherFactory::class, [[PasswordAuthenticatedUserInterface::class => ['algorithm' => 'plaintext']]])]);
        }

        $container->compile(true);

        return $container;
    }

    /**
     * @return ServiceLocator<mixed>
     */
    private function locator(ContainerBuilder $container): ServiceLocator
    {
        $locator = $this->service($container, 'test.converter_locator');
        self::assertInstanceOf(ServiceLocator::class, $locator);

        return $locator;
    }

    /**
     * The PHPStan Symfony extension checks constant ids against the test kernel container, not against $container.
     */
    private function service(ContainerInterface $container, string $id): mixed
    {
        return $container->get($id);
    }
}
