<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DebugScimMappingKindsTest extends KernelTestCase
{
    #[Test]
    #[TestDox('debug:scim shows an element collection with a row per element member, and a DQL expression as filterable and sortable')]
    public function showsElementCollectionsAndExpressions(): void
    {
        $result = self::runCommand('debug:scim', ['name' => 'User']);
        $display = (string) preg_replace('/ +/', ' ', $result->getDisplay());

        $this->assertCommandIsSuccessful($result);
        self::assertStringContainsString(' addresses $addresses ElementCollection pr no ', $display);
        self::assertStringContainsString(' addresses.locality UserAddress::$locality Field all yes ', $display);
        self::assertStringContainsString(' addresses.primary UserAddress::$primary Field eq, ne, pr yes ', $display);
        self::assertStringContainsString(' name.formatted getFormattedName() Computed with DQL all yes ', $display);
    }
}
