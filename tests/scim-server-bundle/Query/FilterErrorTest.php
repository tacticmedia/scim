<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\Present;
use TacticMedia\ScimServer\Filter\ValuePathFilter;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\ListQuery;

final class FilterErrorTest extends KernelTestCase
{
    use PlansQueries;
    private const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const string ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';
    private const string GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    private const string DEVICE = 'urn:acme:scim:schemas:core:1.0:Device';
    private const string BADGE = 'urn:acme:scim:schemas:extension:badge:1.0:User';

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function errors(): iterable
    {
        yield 'Computed' => ['Device', 'label eq "x"', "The attribute '".self::DEVICE.":label' cannot be used in a filter."];
        yield 'writeOnly' => ['Contact', 'password eq "x"', "The attribute '".self::USER.":password' is never returned and cannot be used in a filter."];
        yield 'writeOnly in a value path' => ['Contact', 'badge[code eq "1"]', "The attribute '".self::BADGE.":badge.code' is never returned and cannot be used in a filter."];
        yield 'reference displayName' => ['User', 'manager.displayName eq "x"', "The attribute '".self::ENTERPRISE.":manager.displayName' cannot be used in a filter."];
        yield 'unmapped common sub-attribute' => ['User', 'meta.location eq "x"', "The attribute '".self::USER.":meta.location' is not supported."];
        yield 'unmapped common attribute' => ['Device', 'meta.created gt "2026-01-01T00:00:00Z"', "The attribute '".self::DEVICE.":meta.created' is not supported."];
        yield 'co on a uuid id' => ['User', 'id co "01"', "The operator 'co' is not supported for '".self::USER.":id'."];
        yield 'gt on a uuid id' => ['User', 'id gt "0"', "The operator 'gt' is not supported for '".self::USER.":id'."];
        yield 'co on a reference' => ['User', 'manager co "x"', "The operator 'co' is not supported for '".self::ENTERPRISE.":manager.value'."];
    }

    #[Test]
    #[DataProvider('errors')]
    #[TestDox('$filter on $name fails: $message')]
    public function rejects(string $name, string $filter, string $message): void
    {
        $this->assertInvalidFilter($message, fn (): mixed => $this->plan(new ListQuery($this->resolved($filter, $name)), $name));
    }

    #[Test]
    #[TestDox('A filter node of another implementation fails')]
    public function unknownNode(): void
    {
        $node = new class implements Filter {};

        $this->assertInvalidFilter(\sprintf('The filter node %s is not supported.', $node::class), fn (): mixed => $this->plan(new ListQuery($node), 'User'));
    }

    #[Test]
    #[TestDox('A nested value path fails')]
    public function nestedValuePath(): void
    {
        $emails = new AttributePath(self::USER, 'emails');
        $filter = new ValuePathFilter($emails, new ValuePathFilter($emails, new Present($emails->withSubAttribute('value'))));

        $this->assertInvalidFilter("Value paths do not nest: '".self::USER.":emails[...]'.", fn (): mixed => $this->plan(new ListQuery($filter), 'User'));
    }

    #[Test]
    #[TestDox('An unresolvable sub-attribute inside a reference value path fails')]
    public function unresolvableRelationSubAttribute(): void
    {
        $filter = new ValuePathFilter(new AttributePath(self::GROUP, 'members'), new Comparison(new AttributePath(self::GROUP, 'unknown', 'type'), ComparisonOperator::Equal, 'User'));

        $this->assertInvalidFilter("The attribute '".self::GROUP.":unknown.type' is not supported.", fn (): mixed => $this->plan(new ListQuery($filter), 'Group'));
    }

    /**
     * @param \Closure(): mixed $plan
     */
    private function assertInvalidFilter(string $message, \Closure $plan): void
    {
        try {
            $plan();
        } catch (ScimException $scimException) {
            self::assertSame([400, ScimErrorType::InvalidFilter, $message], [$scimException->status, $scimException->scimType, $scimException->detail]);

            return;
        }

        self::fail('No ScimException.');
    }
}
