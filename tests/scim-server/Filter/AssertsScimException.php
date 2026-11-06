<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Filter;

use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Exception\ScimException;

trait AssertsScimException
{
    /**
     * @param \Closure(): mixed $action
     */
    private static function assertThrowsScimException(ScimErrorType $scimType, string $detail, \Closure $action): void
    {
        try {
            $action();
        } catch (ScimException $scimException) {
            self::assertSame([400, $scimType, $detail], [$scimException->status, $scimException->scimType, $scimException->detail]);

            return;
        }

        self::fail('No ScimException was thrown.');
    }
}
