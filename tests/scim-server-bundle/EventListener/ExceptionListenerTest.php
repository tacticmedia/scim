<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\EventListener;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use TacticMedia\ScimServerBundle\EventListener\ExceptionListener;

final class ExceptionListenerTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{\Throwable, int, string, array<string, string>}>
     */
    public static function exceptions(): iterable
    {
        yield '403 with its message' => [new AccessDeniedHttpException('Access Denied.'), 403, 'Access Denied.', []];
        yield '429 with Retry-After' => [new TooManyRequestsHttpException(120, 'Slow down.'), 429, 'Slow down.', ['retry-after' => '120']];
        yield '404 without a message, with the status text' => [new HttpException(404), 404, 'Not Found', []];
        yield '401 with a WWW-Authenticate challenge' => [new HttpException(401, 'No token.'), 401, 'No token.', ['www-authenticate' => 'Bearer']];
        yield '503 with its status text, not its message, and its headers' => [new HttpException(503, 'SQLSTATE[08006] connection to server at "db" failed', null, ['Retry-After' => '30']), 503, 'Service Unavailable', ['retry-after' => '30']];
        yield 'any other exception as 500 without internals' => [new \RuntimeException('SQLSTATE[HY000]: secret details'), 500, 'The server failed to process the request.', []];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('exceptions')]
    #[TestDox('On the SCIM route: $_dataName')]
    public function rendersScimErrors(\Throwable $exception, int $status, string $detail, array $headers): void
    {
        $request = Request::create('/scim/v2/Users');
        $request->attributes->set('_route', 'tacticmedia_scim_server');

        $response = $this->handle($request, $exception)->getResponse();

        self::assertSame([$status, 'application/scim+json'], [$response?->getStatusCode(), $response?->headers->get('Content-Type')]);
        self::assertSame(['schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'], 'status' => (string) $status, 'detail' => $detail], json_decode((string) $response?->getContent(), true));
        self::assertSame($headers, array_intersect_key(array_map(static fn (array $values): ?string => $values[0] ?? null, $response?->headers->all() ?? []), $headers));
    }

    /**
     * @return iterable<string, array{Request, \Throwable}>
     */
    public static function otherRequests(): iterable
    {
        $other = Request::create('/other');
        $other->attributes->set('_route', 'other');

        yield 'another route' => [$other, new \RuntimeException('Boom.')];
        yield 'no route and another exception' => [Request::create('/scim/v2/Users'), new \RuntimeException('Boom.')];
    }

    #[Test]
    #[DataProvider('otherRequests')]
    #[TestDox('Leaves the exception to the kernel for $_dataName')]
    public function ignoresOtherRequests(Request $request, \Throwable $exception): void
    {
        self::assertNull($this->handle($request, $exception)->getResponse());
    }

    private function handle(Request $request, \Throwable $exception): ExceptionEvent
    {
        $event = new ExceptionEvent(self::createKernel(), $request, HttpKernelInterface::MAIN_REQUEST, $exception);
        new ExceptionListener()($event);

        return $event;
    }
}
