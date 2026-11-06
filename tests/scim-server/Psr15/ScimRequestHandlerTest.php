<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Psr15;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Psr15\ScimRequestHandler;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

final class ScimRequestHandlerTest extends KernelTestCase
{
    use ServerFixture;

    private Psr17Factory $psr17;

    private ScimRequestHandler $handler;

    protected function setUp(): void
    {
        $this->psr17 = new Psr17Factory();
        $this->handler = new ScimRequestHandler(
            $this->server([self::storedUser('u1', 'bjensen')]),
            '/scim/v2',
            new PsrHttpFactory($this->psr17, $this->psr17, $this->psr17, $this->psr17),
        );
    }

    #[Test]
    #[TestDox('Creates a resource from a JSON body')]
    public function createsResource(): void
    {
        $response = $this->handle('POST', '/Users', json_encode(['schemas' => [self::USER], 'userName' => 'jsmith'], \JSON_THROW_ON_ERROR));

        $id = $this->body($response)['id'] ?? null;
        self::assertSame(201, $response->getStatusCode());
        self::assertIsString($id);
        self::assertSame(self::BASE_URL.'/Users/'.$id, $response->getHeaderLine('Location'));
        self::assertSame('jsmith', $this->users->get($id)['userName'] ?? null);
    }

    #[Test]
    #[TestDox('Answers HEAD with the GET headers and no body')]
    public function answersHeadWithoutBody(): void
    {
        $response = $this->handle('HEAD', '/Users/u1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('W/"1"', $response->getHeaderLine('ETag'));
        self::assertSame('', (string) $response->getBody());
    }

    private function handle(string $method, string $uri, ?string $body = null): ResponseInterface
    {
        $request = $this->psr17->createServerRequest($method, self::BASE_URL.$uri);

        if (null !== $body) {
            $request = $request->withHeader('Content-Type', 'application/scim+json')->withBody($this->psr17->createStream($body));
        }

        return $this->handler->handle($request);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function body(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }
}
