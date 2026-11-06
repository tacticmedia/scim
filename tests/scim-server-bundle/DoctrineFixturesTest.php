<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineFixturesTest extends KernelTestCase
{
    #[Test]
    #[TestDox('The fixture entities form a valid Doctrine mapping')]
    public function fixtureEntitiesAreValid(): void
    {
        $this->assertCommandIsSuccessful(self::runCommand('doctrine:schema:validate', ['--skip-sync' => true]));
    }
}
