<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use TacticMedia\ScimServerBundle\Security\TokenMeResolver;

final class TokenMeResolverTest extends KernelTestCase
{
    #[Test]
    #[TestDox('No token: no subject')]
    public function noTokenIsNoSubject(): void
    {
        self::assertNull(new TokenMeResolver(new TokenStorage(), 'User', 'userName')->resolve(new Request()));
    }
}
