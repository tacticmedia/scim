<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Filter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\FilterEvaluator;
use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Filter\FilterResolver;
use TacticMedia\ScimServer\Filter\Present;
use TacticMedia\ScimServer\Filter\ValuePathFilter;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\SchemaUrn;

final class FilterEvaluatorTest extends KernelTestCase
{
    use AssertsScimException;

    /**
     * @param array<string, mixed> $document
     */
    #[Test]
    #[DataProviderExternal(FilterEvaluatorCases::class, 'strings')]
    #[DataProviderExternal(FilterEvaluatorCases::class, 'orderings')]
    #[DataProviderExternal(FilterEvaluatorCases::class, 'otherTypes')]
    #[DataProviderExternal(FilterEvaluatorCases::class, 'presence')]
    #[DataProviderExternal(FilterEvaluatorCases::class, 'logic')]
    #[DataProviderExternal(FilterEvaluatorCases::class, 'valuePaths')]
    #[DataProviderExternal(FilterEvaluatorCases::class, 'documentsWithValuesThatDoNotFit')]
    #[TestDox('Evaluates $filter to $expected')]
    public function evaluatesFilter(string $filter, bool $expected, array $document = FilterEvaluatorCases::DOCUMENT): void
    {
        $type = UserResourceType::create();
        $resolved = new FilterResolver()->resolve(new FilterParser()->parse($filter), $type);

        self::assertSame($expected, new FilterEvaluator()->matches($resolved, $document, $type));
    }

    /**
     * @return iterable<string, array{Filter, bool}>
     */
    public static function unresolvedFilters(): iterable
    {
        $unknown = new AttributePath(null, 'unknown');

        yield 'pr on an unknown attribute' => [new Present($unknown), false];
        yield 'eq on an unknown attribute' => [new Comparison($unknown, ComparisonOperator::Equal, 'x'), false];
        yield 'ne on an unknown attribute' => [new Comparison($unknown, ComparisonOperator::NotEqual, 'x'), true];
        yield 'eq null on an unknown attribute' => [new Comparison($unknown, ComparisonOperator::Equal, null), true];
        yield 'value path on an unknown attribute' => [new ValuePathFilter($unknown, new Present(new AttributePath(null, 'unknown', 'type'))), false];
        yield 'co null' => [new Comparison(new AttributePath(null, 'title'), ComparisonOperator::Contains, null), false];
        yield 'path in another case' => [new Comparison(new AttributePath(null, 'USERNAME'), ComparisonOperator::Equal, 'bjensen'), true];
    }

    #[Test]
    #[DataProvider('unresolvedFilters')]
    #[TestDox('Evaluates an unresolved filter: $_dataName')]
    public function evaluatesUnresolvedFilter(Filter $filter, bool $expected): void
    {
        self::assertSame($expected, new FilterEvaluator()->matches($filter, FilterEvaluatorCases::DOCUMENT, UserResourceType::create()));
    }

    #[Test]
    #[TestDox('Rejects a filter node that it does not know')]
    public function rejectsUnknownFilterNode(): void
    {
        self::assertThrowsScimException(
            ScimErrorType::InvalidFilter,
            'The filter node '.UnknownFilter::class.' is not supported here.',
            static fn (): bool => new FilterEvaluator()->matches(new UnknownFilter(), FilterEvaluatorCases::DOCUMENT, UserResourceType::create()),
        );
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, bool}>
     */
    public static function elements(): iterable
    {
        yield 'work email matches type' => ['emails.type eq "work"', FilterEvaluatorCases::DOCUMENT['emails'][0], true];
        yield 'home email does not match type' => ['emails.type eq "work"', FilterEvaluatorCases::DOCUMENT['emails'][1], false];
        yield 'logical filter' => ['emails.type eq "home" and not (emails.primary eq true)', FilterEvaluatorCases::DOCUMENT['emails'][1], true];
        yield 'unassigned sub-attribute' => ['emails.display pr', FilterEvaluatorCases::DOCUMENT['emails'][0], false];
    }

    /**
     * @param array<string, mixed> $element
     */
    #[Test]
    #[DataProvider('elements')]
    #[TestDox('Evaluates a value filter against one element: $_dataName')]
    public function evaluatesValueFilterAgainstElement(string $filter, array $element, bool $expected): void
    {
        $type = UserResourceType::create();
        $resolved = new FilterResolver()->resolve(new FilterParser()->parse($filter), $type);
        $emails = $type->resolve(new AttributePath(null, 'emails'));
        self::assertNotNull($emails);

        self::assertSame($expected, new FilterEvaluator()->matchesElement($resolved, $emails, $element));
    }

    /**
     * @return iterable<string, array{Filter}>
     */
    public static function elementPathsOutsideTheAttribute(): iterable
    {
        yield 'unknown sub-attribute' => [new Present(new AttributePath(null, 'emails', 'foo'))];
        yield 'no sub-attribute' => [new Present(new AttributePath(null, 'emails'))];
    }

    #[Test]
    #[DataProvider('elementPathsOutsideTheAttribute')]
    #[TestDox('A value filter path outside the element matches nothing: $_dataName')]
    public function valueFilterPathOutsideElementMatchesNothing(Filter $filter): void
    {
        $emails = UserResourceType::create()->resolve(new AttributePath(null, 'emails'));
        self::assertNotNull($emails);

        self::assertFalse(new FilterEvaluator()->matchesElement($filter, $emails, FilterEvaluatorCases::DOCUMENT['emails'][0]));
    }

    #[Test]
    #[TestDox('Rejects a value path inside a value filter')]
    public function rejectsNestedValuePath(): void
    {
        $emails = UserResourceType::create()->resolve(new AttributePath(null, 'emails'));
        self::assertNotNull($emails);
        $nested = new ValuePathFilter(new AttributePath(null, 'emails'), new Present(new AttributePath(null, 'emails', 'type')));

        self::assertThrowsScimException(
            ScimErrorType::InvalidFilter,
            'The filter node '.ValuePathFilter::class.' is not supported here.',
            static fn (): bool => new FilterEvaluator()->matchesElement($nested, $emails, FilterEvaluatorCases::DOCUMENT['emails'][0]),
        );
    }

    #[Test]
    #[TestDox('Extension attributes are unassigned when the document has no extension object')]
    public function extensionAttributesAreUnassignedWithoutExtensionObject(): void
    {
        $type = UserResourceType::create();
        $document = FilterEvaluatorCases::DOCUMENT;
        unset($document[SchemaUrn::EnterpriseUser->value]);
        $resolver = new FilterResolver();
        $parser = new FilterParser();

        self::assertSame(
            [false, true],
            [
                new FilterEvaluator()->matches($resolver->resolve($parser->parse('employeeNumber pr'), $type), $document, $type),
                new FilterEvaluator()->matches($resolver->resolve($parser->parse('employeeNumber ne "701984"'), $type), $document, $type),
            ],
        );
    }
}
