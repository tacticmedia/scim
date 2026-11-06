<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Event\ResourceDeleted;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Tests\Server\Fixtures\ServerFixture;

final class DeleteTest extends KernelTestCase
{
    use ServerFixture;

    #[Test]
    #[TestDox('DELETE answers 204 without a body, dispatches ResourceDeleted, and a later GET answers 404')]
    public function deletesResource(): void
    {
        $stored = self::storedGroup('g1', 'Tour Guides');
        $server = $this->server(groups: [$stored]);

        $response = $this->send($server, 'DELETE', '/Groups/g1', headers: ['If-Match' => 'W/"1"']);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertEquals([new ResourceDeleted(CoreResourceTypes::group(), $stored)], $this->events);
        self::assertSame(404, $this->send($server, 'GET', '/Groups/g1')->getStatusCode());
    }

    #[Test]
    #[TestDox('DELETE of an unknown id answers 404')]
    public function answersNotFound(): void
    {
        $server = $this->server();

        self::assertScimError($this->send($server, 'DELETE', '/Groups/g9'), 404, null, 'Resource g9 not found.');
    }

    #[Test]
    #[TestDox('DELETE with an If-Match that does not match answers 412 and keeps the resource')]
    public function answersPreconditionFailed(): void
    {
        $server = $this->server(groups: [self::storedGroup('g1', 'Tour Guides')]);

        $response = $this->send($server, 'DELETE', '/Groups/g1', headers: ['If-Match' => 'W/"2"']);

        self::assertScimError($response, 412, null, 'Failed to update. Resource has changed on the server.');
        self::assertNotNull($this->groups->get('g1'));
        self::assertSame([], $this->events);
    }

    #[Test]
    #[TestDox('DELETE with an If-None-Match that matches answers 412 and keeps the resource (RFC 7232 section 3.2)')]
    public function answersIfNoneMatchFailed(): void
    {
        $server = $this->server(groups: [self::storedGroup('g1', 'Tour Guides')]);

        $response = $this->send($server, 'DELETE', '/Groups/g1', headers: ['If-None-Match' => 'W/"1"']);

        self::assertScimError($response, 412, null, 'The resource matches If-None-Match.');
        self::assertNotNull($this->groups->get('g1'));
    }
}
