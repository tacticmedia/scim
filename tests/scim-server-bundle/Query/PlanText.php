<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Query;

use Symfony\Component\Uid\AbstractUid;
use TacticMedia\ScimServerBundle\Query\ObjectQuery;
use TacticMedia\ScimServerBundle\Query\Plan\AllOf;
use TacticMedia\ScimServerBundle\Query\Plan\AnyOf;
use TacticMedia\ScimServerBundle\Query\Plan\Compare;
use TacticMedia\ScimServerBundle\Query\Plan\Exists;
use TacticMedia\ScimServerBundle\Query\Plan\IsPresent;
use TacticMedia\ScimServerBundle\Query\Plan\Negate;
use TacticMedia\ScimServerBundle\Query\Plan\Node;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\Tag;

/**
 * A plan as text: and(...), or(...), not(...), exists($relation[, condition]), "$member pr", "$member op value".
 */
final class PlanText
{
    public static function of(ObjectQuery|false $query): string
    {
        return false === $query ? 'false' : self::node($query->filter ?? true);
    }

    private static function node(Node|bool $node): string
    {
        return match (true) {
            \is_bool($node) => var_export($node, true),
            $node instanceof AllOf => 'and('.self::nodes($node->nodes).')',
            $node instanceof AnyOf => 'or('.self::nodes($node->nodes).')',
            $node instanceof Negate => 'not('.self::node($node->node).')',
            $node instanceof IsPresent => $node->operand->label().' pr',
            $node instanceof Compare => \sprintf('%s %s %s', $node->operand->label(), $node->operator->value, self::value($node->value)),
            $node instanceof Exists => 'exists('.$node->relation->label().($node->condition instanceof Node ? ', '.self::node($node->condition) : '').')',
            default => throw new \LogicException(\sprintf('Unknown plan node %s.', $node::class)),
        };
    }

    /**
     * @param list<Node> $nodes
     */
    private static function nodes(array $nodes): string
    {
        return implode(', ', array_map(self::node(...), $nodes));
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value instanceof \DateTimeImmutable => $value->format('Y-m-d\TH:i:sP'),
            $value instanceof AbstractUid => 'uid:'.$value,
            $value instanceof Tag => 'tag:'.$value->code,
            default => json_encode($value, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
        };
    }
}
