<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use TacticMedia\ScimServer\Server\MeSubject;
use TacticMedia\ScimServerBundle\Security\TokenMeResolver;

final class TokenMeResolverTest extends KernelTestCase
{
    #[Test]
    #[TestDox('The user identifier of the token is the value of the configured attribute')]
    public function resolvesTheUserIdentifier(): void
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ada@example.com', null), 'main'));

        self::assertEquals(new MeSubject('User', 'ada@example.com', 'userName'), new TokenMeResolver($tokens, 'User', 'userName')->resolve(new Request()));
    }

    #[Test]
    #[TestDox('No token: no subject')]
    public function noTokenIsNoSubject(): void
    {
        self::assertNull(new TokenMeResolver(new TokenStorage(), 'User', 'userName')->resolve(new Request()));
    }
}
