<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Exception;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimException;

final class ScimExceptionTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{\Closure(): ScimException, array{schemas: list<string>, status: string, scimType?: string, detail: string}}>
     */
    public static function errors(): iterable
    {
        $error = ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'], 'status' => '400'];

        yield 'tooMany' => [static fn (): ScimException => ScimException::tooMany('Too many.'), [...$error, 'scimType' => 'tooMany', 'detail' => 'Too many.']];
        yield 'invalidVers' => [static fn (): ScimException => ScimException::invalidVersion('Version.'), [...$error, 'scimType' => 'invalidVers', 'detail' => 'Version.']];
        yield 'invalidCursor, one fixed detail' => [ScimException::invalidCursor(...), [...$error, 'scimType' => 'invalidCursor', 'detail' => 'The cursor is not valid for this query.']];
        yield 'expiredCursor' => [ScimException::expiredCursor(...), [...$error, 'scimType' => 'expiredCursor', 'detail' => 'The cursor has expired. Request the first page again.']];
        yield 'invalidCount' => [static fn (): ScimException => ScimException::invalidCount('Count.'), [...$error, 'scimType' => 'invalidCount', 'detail' => 'Count.']];
    }

    /**
     * @param \Closure(): ScimException                                                       $exception
     * @param array{schemas: list<string>, status: string, scimType?: string, detail: string} $expected
     */
    #[Test]
    #[DataProvider('errors')]
    #[TestDox('$_dataName: status 400 and its scimType')]
    public function rendersError(\Closure $exception, array $expected): void
    {
        self::assertSame($expected, $exception()->toArray());
    }
}
