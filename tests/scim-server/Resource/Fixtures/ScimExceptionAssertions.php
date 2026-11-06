<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Resource\Fixtures;

use PHPUnit\Framework\Assert;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Exception\ScimException;

trait ScimExceptionAssertions
{
    /**
     * @param \Closure(): mixed $action
     */
    private static function assertScimException(ScimErrorType $scimType, string $detail, \Closure $action): void
    {
        try {
            $action();
        } catch (ScimException $scimException) {
            Assert::assertSame([$scimType, $detail], [$scimException->scimType, $scimException->detail]);

            return;
        }

        Assert::fail('No ScimException was thrown.');
    }
}
