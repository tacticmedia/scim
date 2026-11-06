<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use TacticMedia\ScimServerBundle\Security\AuthenticationFailureHandler;

final class AuthenticationFailureHandlerTest extends KernelTestCase
{
    #[Test]
    #[TestDox('The 401 detail is the message key with the message data substituted')]
    public function substitutesMessageData(): void
    {
        $exception = new CustomUserMessageAuthenticationException('Token of tenant %tenant% expired.', ['%tenant%' => 'acme']);

        $response = new AuthenticationFailureHandler()->onAuthenticationFailure(new Request(), $exception);

        self::assertSame([401, 'Token of tenant acme expired.'], [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['detail'] ?? null]);
    }
}
