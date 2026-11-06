<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Server\RequestedAttributes;

final class RequestedAttributesTest extends KernelTestCase
{
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    #[Test]
    #[TestDox('inBody() gives the body keys in order, an extension object as one path per key')]
    public function namesBodyKeys(): void
    {
        $paths = RequestedAttributes::inBody(['userName' => 'bjensen', self::ENTERPRISE => ['employeeNumber' => '1'], '1nvalid' => true, 'name' => []], CoreResourceTypes::user());

        self::assertSame(['userName', self::ENTERPRISE.':employeeNumber', 'name'], array_map(strval(...), $paths));
    }

    #[Test]
    #[TestDox('inBody() takes linear time: 40,000 unknown keys in less than a second')]
    public function namesManyKeysFast(): void
    {
        $body = array_fill_keys(array_map(static fn (int $index): string => 'k'.$index, range(1, 40_000)), 1);
        $startNanoseconds = hrtime(true);

        $paths = RequestedAttributes::inBody($body, CoreResourceTypes::user());

        self::assertLessThan(1_000_000_000, hrtime(true) - $startNanoseconds);
        self::assertCount(40_000, $paths);
    }
}
