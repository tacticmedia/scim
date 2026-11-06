<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use TacticMedia\ScimServerBundle\Security\AuthorizationTokenExtractor;

final class AuthorizationTokenExtractorTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function headers(): iterable
    {
        yield 'Bearer' => ['Bearer a.b-c_d~e+f/g==', 'a.b-c_d~e+f/g=='];
        yield 'BEARER in upper case and more spaces' => ['BEARER   token', 'token'];
        yield 'a bare token (Okta Header authentication)' => ['token', 'token'];
        yield 'another scheme' => ['Basic dXNlcjpwYXNz', null];
        yield 'a scheme without a token' => ['Bearer ', null];
        yield 'a token with a trailing newline' => ["Bearer token\n", null];
        yield 'no header' => [null, null];
    }

    #[Test]
    #[DataProvider('headers')]
    #[TestDox('Authorization: $_dataName')]
    public function extractsTheToken(?string $authorization, ?string $token): void
    {
        $request = new Request();
        $request->headers->set('Authorization', $authorization);

        self::assertSame($token, new AuthorizationTokenExtractor()->extractAccessToken($request));
    }
}
