<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;

final class CoreResourceTypesTest extends KernelTestCase
{
    #[Test]
    #[TestDox('User is RFC 7643 Figure 8 with an optional Enterprise User extension')]
    public function userIsFigure8WithAnOptionalEnterpriseUserExtension(): void
    {
        self::assertSame([
            'id' => 'User',
            'name' => 'User',
            'endpoint' => '/Users',
            'description' => 'User Account',
            'schema' => 'urn:ietf:params:scim:schemas:core:2.0:User',
            'schemaExtensions' => [
                ['schema' => 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User', 'required' => false],
            ],
        ], CoreResourceTypes::user()->toArray());
    }

    #[Test]
    #[TestDox('Group is RFC 7643 Figure 8 without extensions')]
    public function groupIsFigure8WithoutExtensions(): void
    {
        self::assertSame([
            'id' => 'Group',
            'name' => 'Group',
            'endpoint' => '/Groups',
            'description' => 'Group',
            'schema' => 'urn:ietf:params:scim:schemas:core:2.0:Group',
            'schemaExtensions' => [],
        ], CoreResourceTypes::group()->toArray());
    }
}
