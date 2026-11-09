<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use TacticMedia\ScimServerBundle\DependencyInjection\Compiler\MappingPass;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\TacticMediaScimServerBundle;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\GroupPrefixedUser;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\MissingId;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\NoScimResource;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\WrongPhpType;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\IntGroup;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\IntUser;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\Person;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\PrefixedGroup;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\PrefixedUser;

final class MappingPassTest extends KernelTestCase
{
    #[Test]
    #[TestDox('Autoconfigured #[ScimResource] classes become the class parameter and file resources')]
    public function autoconfiguredClassesAreStored(): void
    {
        $container = $this->compile(Group::class, User::class);
        $files = array_map(static fn (FileResource $resource): string => $resource->getResource(), array_values(array_filter($container->getResources(), static fn (object $resource): bool => $resource instanceof FileResource)));

        self::assertSame(['Group' => Group::class, 'User' => User::class], $this->classes($container));
        self::assertContains((string) new \ReflectionClass(User::class)->getFileName(), $files);
        self::assertContains((string) new \ReflectionClass(Group::class)->getFileName(), $files);
    }

    #[Test]
    #[TestDox('The build fails with the errors of every invalid class')]
    public function invalidClassesFailTheBuild(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf("2 SCIM mapping errors:\n- %s::\$enabled: \"active\" is boolean, the member is ?string. Use bool or set converter:.\n- %s: map \"id\" or add a property \$id.", WrongPhpType::class, MissingId::class));

        $this->compile(WrongPhpType::class, MissingId::class);
    }

    #[Test]
    #[TestDox('The build fails for a reference to a resource type that no class maps')]
    public function unmappedTargetFailsTheBuild(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('%s::$groups: "groups" refers to Group, which no class maps. Map a class with #[ScimResource(\'Group\')].', User::class));

        $this->compile(User::class);
    }

    #[Test]
    #[TestDox('The build fails for two classes with one name or one endpoint')]
    public function duplicateNamesAndEndpointsFailTheBuild(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('- %s and %s use one resource type name.', User::class, IntUser::class));
        $this->expectExceptionMessageIsOrContains(\sprintf('- %s and %s and %s use one endpoint.', User::class, IntUser::class, Person::class));

        $this->compile(User::class, Group::class, IntUser::class, Person::class);
    }

    #[Test]
    #[TestDox('The build fails when untyped member ids of int-id targets are ambiguous')]
    public function ambiguousIntIdsFailTheBuild(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('%s: "urn:ietf:params:scim:schemas:core:2.0:Group:members" refers to User and Group, which have int ids, so a member id is ambiguous. Set idPrefix: on both, or use UUIDs.', IntGroup::class));

        $this->compile(IntUser::class, IntGroup::class);
    }

    #[Test]
    #[TestDox('The build fails when two int-id targets of a reference collection share an idPrefix')]
    public function sharedIdPrefixFailsTheBuild(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('%s: "urn:ietf:params:scim:schemas:core:2.0:Group:members" refers to User and Group, which have int ids with the idPrefix "g", so a member id is ambiguous. Give each its own idPrefix:, or use UUIDs.', PrefixedGroup::class));

        $this->compile(GroupPrefixedUser::class, PrefixedGroup::class);
    }

    #[Test]
    #[TestDox('Int-id targets with distinct idPrefixes build')]
    public function distinctIdPrefixesBuild(): void
    {
        self::assertSame(['User' => PrefixedUser::class, 'Group' => PrefixedGroup::class], $this->classes($this->compile(PrefixedUser::class, PrefixedGroup::class)));
    }

    #[Test]
    #[TestDox('The classes of resources join the discovered classes, each once, also with a leading backslash')]
    public function configuredClassesJoinDiscovery(): void
    {
        $container = $this->configured(['resources' => ['\\'.User::class, '\\'.Group::class]], Group::class);

        self::assertSame(['Group' => Group::class, 'User' => User::class], $this->classes($container));
        self::assertContains((string) new \ReflectionClass(User::class)->getFileName(), array_map(strval(...), $container->getResources()));
    }

    #[Test]
    #[TestDox('A missing class or a class without #[ScimResource] in resources fails the build')]
    public function invalidConfiguredClassesFailTheBuild(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf("2 SCIM mapping errors:\n- App\\Entity\\Missing: the class does not exist. Correct the class name in tacticmedia_scim_server.resources, or remove it.\n- %s: the class has no #[ScimResource] attribute. Add #[ScimResource], or remove the class from tacticmedia_scim_server.resources.", NoScimResource::class));

        $this->configured(['resources' => ['App\\Entity\\Missing', NoScimResource::class]]);
    }

    #[Test]
    #[TestDox('A resource tag on a service whose class does not exist fails the build')]
    public function missingTaggedClassFailsTheBuild(): void
    {
        $container = $this->container([]);
        $container->register('app.missing', 'App\\Entity\\Missing')->addResourceTag(MappingPass::RESOURCE_TAG);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessageIsOrContains('App\\Entity\\Missing: the class of the service "app.missing" does not exist. Correct the class of the service, or remove its tacticmedia_scim_server.resource tag.');

        $container->compile(true);
    }

    /**
     * The extension types the parameter with its value in the test kernel, which another container does not share.
     */
    private function classes(ContainerBuilder $container): mixed
    {
        return $container->getParameter(MappingPass::CLASSES_PARAMETER);
    }

    /**
     * @param class-string ...$classes
     */
    private function compile(string ...$classes): ContainerBuilder
    {
        return $this->configured([], ...$classes);
    }

    /**
     * @param array<string, mixed> $config
     * @param class-string         ...$classes
     */
    private function configured(array $config, string ...$classes): ContainerBuilder
    {
        $container = $this->container($config);

        foreach ($classes as $class) {
            $container->register($class, $class)->setAutoconfigured(true);
        }

        $container->compile(true);

        return $container;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function container(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $bundle = new TacticMediaScimServerBundle();
        $bundle->build($container);

        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);

        return $container;
    }
}
