<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\Tests\IdentityProvider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimDemo\IdentityProvider\Kernel;

final class KernelTest extends KernelTestCase
{
    #[Test]
    #[TestDox('The kernel parameters omit ".kernel.config_dir", so a debug build writes no config/reference.php')]
    public function omitsConfigDirectory(): void
    {
        $parameters = new \ReflectionMethod(Kernel::class, 'getKernelParameters')->invoke(new Kernel('test', true));

        self::assertIsArray($parameters);
        self::assertArrayNotHasKey('.kernel.config_dir', $parameters);
        self::assertSame(\dirname(__DIR__, 3).'/demo/identity-provider', $parameters['kernel.project_dir'] ?? null);
    }
}
