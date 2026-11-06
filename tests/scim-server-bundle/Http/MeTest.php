<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Environment me: the client name of the token is the userName of a User.
 */
final class MeTest extends WebTestCase
{
    use ScimClient;

    #[Test]
    #[TestDox('GET /Me answers the User whose userName is the client name, with its Location')]
    public function meIsTheUserOfTheClient(): void
    {
        $id = self::idOf($this->send('POST', '/scim/v2/Users', ['schemas' => [Scim::USER], 'userName' => 'Entra']));
        $response = $this->send('GET', '/scim/v2/Me');

        self::assertSame([200, $id, Scim::BASE_URL.'/Users/'.$id], [$response->getStatusCode(), self::idOf($response), $response->headers->get('Location')]);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel(['environment' => 'me'] + $options);
    }
}
