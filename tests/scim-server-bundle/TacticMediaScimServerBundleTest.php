<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TacticMediaScimServerBundleTest extends KernelTestCase
{
    #[TestDox('The container registers the bundle extension as tacticmedia_scim_server')]
    public function testTheContainerRegistersTheBundleExtension(): void
    {
        $this->assertCommandIsSuccessful(self::runCommand('debug:config', ['name' => 'tacticmedia_scim_server']));
    }
}
