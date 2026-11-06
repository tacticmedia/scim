<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServerBundle\Tests\Query\PlansQueries;
use TacticMedia\ScimServerBundle\Tests\Query\PlanText;

final class ContractsTest extends KernelTestCase
{
    use PlansQueries;
    private const string ID = '0193a8e2-5c6f-7b4e-9f2a-3c1d2e4f5a6b';

    #[Test]
    #[TestDox('members.value eq "<id>" and not (externalId pr) plans over both reference collections')]
    public function membersValuePlan(): void
    {
        $query = $this->plan(new ListQuery($this->resolved('members.value eq "'.self::ID.'" and not (externalId pr)', 'Group')), 'Group');

        self::assertSame('and(or(exists($users, $id eq uid:'.self::ID.'), exists($subgroups, $id eq uid:'.self::ID.')), not($externalId pr))', PlanText::of($query));
    }
}
