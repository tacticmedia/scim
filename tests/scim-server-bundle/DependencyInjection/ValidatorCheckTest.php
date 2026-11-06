<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use TacticMedia\ScimServerBundle\DependencyInjection\Compiler\MappingPass;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\TacticMediaScimServerBundle;
use TacticMedia\ScimServerBundle\Tests\Validation\Fixtures\Member;

final class ValidatorCheckTest extends KernelTestCase
{
    #[Test]
    #[TestDox('validationGroups without the validator service fails the build')]
    public function missingValidatorFails(): void
    {
        $this->expectExceptionObject(new MappingException([Member::class.': validationGroups: needs the validator service. Install symfony/validator and enable framework.validation, or remove validationGroups:.']));

        $this->compile(false);
    }

    #[Test]
    #[TestDox('validationGroups with the validator service builds')]
    public function validatorBuilds(): void
    {
        self::assertSame(['User' => Member::class], $this->classes($this->compile(true)));
    }

    /**
     * The extension types the parameter with its value in the test kernel, which another container does not share.
     */
    private function classes(ContainerBuilder $container): mixed
    {
        return $container->getParameter(MappingPass::CLASSES_PARAMETER);
    }

    private function compile(bool $validator): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $bundle = new TacticMediaScimServerBundle();
        $bundle->build($container);

        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias());
        $container->register(Member::class, Member::class)->setAutoconfigured(true);

        if ($validator) {
            $container->register('validator', \stdClass::class);
        }

        $container->compile(true);

        return $container;
    }
}
