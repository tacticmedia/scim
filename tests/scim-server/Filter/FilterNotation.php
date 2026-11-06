<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Filter;

use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\LogicalAnd;
use TacticMedia\ScimServer\Filter\LogicalOr;
use TacticMedia\ScimServer\Filter\Not;
use TacticMedia\ScimServer\Filter\Present;
use TacticMedia\ScimServer\Filter\ValuePathFilter;

/**
 * A filter tree as text: every and/or in parentheses, values as JSON with the zero fraction of a float.
 */
final class FilterNotation
{
    public static function of(Filter $filter): string
    {
        return match (true) {
            $filter instanceof Comparison => \sprintf('%s %s %s', $filter->path, $filter->operator->value, json_encode($filter->value, \JSON_PRESERVE_ZERO_FRACTION | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)),
            $filter instanceof Present => $filter->path.' pr',
            $filter instanceof LogicalAnd => \sprintf('(%s and %s)', self::of($filter->left), self::of($filter->right)),
            $filter instanceof LogicalOr => \sprintf('(%s or %s)', self::of($filter->left), self::of($filter->right)),
            $filter instanceof Not => \sprintf('not(%s)', self::of($filter->filter)),
            $filter instanceof ValuePathFilter => \sprintf('%s[%s]', $filter->path, self::of($filter->filter)),
            default => throw new \LogicException($filter::class),
        };
    }
}
