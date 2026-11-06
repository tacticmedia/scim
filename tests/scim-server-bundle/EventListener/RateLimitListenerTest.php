<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\EventListener;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Reservation;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use TacticMedia\ScimServerBundle\Controller\ScimController;
use TacticMedia\ScimServerBundle\EventListener\RateLimitListener;

final class RateLimitListenerTest extends KernelTestCase
{
    #[Test]
    #[TestDox('A retry time in the current second gives Retry-After: 1')]
    public function retryAfterIsAtLeastOneSecond(): void
    {
        $limiter = new class implements RateLimiterFactoryInterface, LimiterInterface {
            public function create(?string $key = null): LimiterInterface
            {
                return $this;
            }

            public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
            {
                throw new \LogicException('Not used.');
            }

            public function consume(int $tokens = 1): RateLimit
            {
                return new RateLimit(0, new \DateTimeImmutable(), false, 1);
            }

            public function reset(): void
            {
            }
        };

        $response = $this->handle(new RateLimitListener($limiter, null), $this->scimRequest())->getResponse();

        self::assertSame([429, '1', 'application/scim+json'], [$response?->getStatusCode(), $response?->headers->get('Retry-After'), $response?->headers->get('Content-Type')]);
        self::assertSame(['schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'], 'status' => '429', 'detail' => 'Too many requests.'], json_decode((string) $response?->getContent(), true));
    }

    #[Test]
    #[TestDox('Without a token or token storage, the client IP is the key')]
    public function keysByClientIpWithoutToken(): void
    {
        foreach ([new TokenStorage(), null] as $tokens) {
            $listener = new RateLimitListener($this->oneRequestPerHour(), $tokens);
            $statuses = array_map(fn (string $ip): ?int => $this->handle($listener, $this->scimRequest($ip))->getResponse()?->getStatusCode(), ['10.0.0.1', '10.0.0.1', '10.0.0.2']);

            self::assertSame([null, 429, null], $statuses);
        }
    }

    #[Test]
    #[TestDox('Other routes and sub-requests are not limited')]
    public function limitsOnlyMainScimRequests(): void
    {
        $listener = new RateLimitListener($this->oneRequestPerHour(), null);
        $other = Request::create('/other');
        $other->attributes->set('_route', 'other');
        $this->handle($listener, $this->scimRequest());

        self::assertNull($this->handle($listener, $other)->getResponse());
        self::assertNull($this->handle($listener, Request::create('/scim/v2/Users'))->getResponse());
        self::assertNull($this->handle($listener, $this->scimRequest(), HttpKernelInterface::SUB_REQUEST)->getResponse());
    }

    private function oneRequestPerHour(): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage());
    }

    private function scimRequest(string $ip = '127.0.0.1'): Request
    {
        $request = Request::create('/scim/v2/Users', server: ['REMOTE_ADDR' => $ip]);
        $request->attributes->set('_route', ScimController::ROUTE);

        return $request;
    }

    private function handle(RateLimitListener $listener, Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $event = new RequestEvent(self::createKernel(), $request, $type);
        $listener($event);

        return $event;
    }
}
