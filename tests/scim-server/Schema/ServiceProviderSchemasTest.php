<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\CoreSchemas;
use TacticMedia\ScimServer\Schema\Schema;

/**
 * RFC 7643 section 8.7.2 (Figure 10) with errata 5606, 5607, 6727, 7522, 8011, 8415, 8418, 8419, 8475 and 9018, and
 * ResourceType schemaExtensions optional as section 6 says, and ServiceProviderConfig pagination of RFC 9865 section 4.
 * Every attribute is readOnly and returned by default.
 */
final class ServiceProviderSchemasTest extends KernelTestCase
{
    private const string SUPPORTED = 'A Boolean value specifying whether or not the operation is supported.';
    private const string TYPE = "The attribute's data type. Valid values include ";
    private const array MUTABILITY = ['readOnly', 'readWrite', 'immutable', 'writeOnly'];
    private const array RETURNED = ['always', 'never', 'default', 'request'];
    private const array UNIQUENESS = ['none', 'server', 'global'];

    /**
     * @return iterable<string, array{string, string, string, bool, bool, bool, string, list<string>, list<string>, string}>
     */
    public static function characteristics(): iterable
    {
        yield from self::serviceProviderConfig();
        yield from self::resourceType();
        yield from self::schema('attributes', ['string', 'complex', 'boolean', 'decimal', 'integer', 'dateTime', 'reference', 'binary']);
        yield from self::schema('attributes.subAttributes', ['string', 'boolean', 'decimal', 'integer', 'dateTime', 'reference', 'binary']);
    }

    /**
     * @param list<string> $canonicalValues
     * @param list<string> $referenceTypes
     */
    #[Test]
    #[DataProvider('characteristics')]
    #[TestDox('$set $path has the RFC characteristics and description')]
    public function attributeHasTheRfcCharacteristics(string $set, string $path, string $type, bool $multiValued, bool $required, bool $caseExact, string $uniqueness, array $canonicalValues, array $referenceTypes, string $description): void
    {
        $definition = self::definition(self::set($set)->attributes, explode('.', $path));

        self::assertSame(
            [$type, $multiValued, $required, $caseExact, 'readOnly', 'default', $uniqueness, $canonicalValues, $referenceTypes, $description],
            [$definition->type->value, $definition->multiValued, $definition->required, $definition->caseExact, $definition->mutability->value, $definition->returned->value, $definition->uniqueness->value, $definition->canonicalValues, $definition->referenceTypes, $definition->description],
        );
    }

    #[Test]
    #[TestDox('Each schema holds exactly the table rows, in RFC order')]
    public function eachSetHoldsExactlyTheTableRows(): void
    {
        $expected = [];

        foreach (self::characteristics() as [$set, $path]) {
            $expected[$set][] = $path;
        }

        self::assertSame($expected, array_map(static fn (string $set): array => self::paths(self::set($set)->attributes), array_combine(array_keys($expected), array_keys($expected))));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function schemas(): iterable
    {
        yield 'ServiceProviderConfig' => ['ServiceProviderConfig', 'urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig', 'Service Provider Configuration', "Schema for representing the service provider's configuration"];
        yield 'ResourceType' => ['ResourceType', 'urn:ietf:params:scim:schemas:core:2.0:ResourceType', 'ResourceType', 'Specifies the schema that describes a SCIM resource type'];
        yield 'Schema' => ['Schema', 'urn:ietf:params:scim:schemas:core:2.0:Schema', 'Schema', 'Specifies the schema that describes a SCIM schema'];
    }

    #[Test]
    #[DataProvider('schemas')]
    #[TestDox('The $set schema has the RFC id, name and description')]
    public function schemaHasTheRfcIdNameAndDescription(string $set, string $id, string $name, string $description): void
    {
        $schema = self::set($set);

        self::assertSame([$id, $name, $description], [$schema->id, $schema->name, $schema->description]);
    }

    /**
     * @return iterable<string, array{string, string, string, bool, bool, bool, string, list<string>, list<string>, string}>
     */
    private static function serviceProviderConfig(): iterable
    {
        $set = 'ServiceProviderConfig';
        yield "{$set}: documentationUri" => [$set, 'documentationUri', 'reference', false, false, false, 'none', [], ['external'], "An HTTP-addressable URL pointing to the service provider's human-consumable help documentation."];

        foreach (['patch' => 'PATCH configuration options', 'bulk' => 'bulk configuration options', 'filter' => 'FILTER options', 'changePassword' => 'configuration options related to changing a password', 'sort' => 'sort result options', 'etag' => 'ETag configuration options'] as $name => $options) {
            yield "{$set}: {$name}" => [$set, $name, 'complex', false, true, false, 'none', [], [], 'A complex type that specifies '.$options.'.'];
            yield "{$set}: {$name}.supported" => [$set, $name.'.supported', 'boolean', false, true, false, 'none', [], [], self::SUPPORTED];

            if ('bulk' === $name) {
                yield "{$set}: bulk.maxOperations" => [$set, 'bulk.maxOperations', 'integer', false, true, false, 'none', [], [], 'An integer value specifying the maximum number of operations.'];
                yield "{$set}: bulk.maxPayloadSize" => [$set, 'bulk.maxPayloadSize', 'integer', false, true, false, 'none', [], [], 'An integer value specifying the maximum payload size in bytes.'];
            }

            if ('filter' === $name) {
                yield "{$set}: filter.maxResults" => [$set, 'filter.maxResults', 'integer', false, true, false, 'none', [], [], 'An integer value specifying the maximum number of resources returned in a response.'];
            }
        }

        yield "{$set}: authenticationSchemes" => [$set, 'authenticationSchemes', 'complex', true, true, false, 'none', [], [], 'A complex type that specifies supported authentication scheme properties.'];
        yield "{$set}: authenticationSchemes.type" => [$set, 'authenticationSchemes.type', 'string', false, true, false, 'none', ['oauth', 'oauth2', 'oauthbearertoken', 'httpbasic', 'httpdigest'], [], 'The authentication scheme.'];
        yield "{$set}: authenticationSchemes.name" => [$set, 'authenticationSchemes.name', 'string', false, true, false, 'none', [], [], 'The common authentication scheme name, e.g., HTTP Basic.'];
        yield "{$set}: authenticationSchemes.description" => [$set, 'authenticationSchemes.description', 'string', false, true, false, 'none', [], [], 'A description of the authentication scheme.'];
        yield "{$set}: authenticationSchemes.specUri" => [$set, 'authenticationSchemes.specUri', 'reference', false, false, false, 'none', [], ['external'], "An HTTP-addressable URL pointing to the authentication scheme's specification."];
        yield "{$set}: authenticationSchemes.documentationUri" => [$set, 'authenticationSchemes.documentationUri', 'reference', false, false, false, 'none', [], ['external'], "An HTTP-addressable URL pointing to the authentication scheme's usage documentation."];
        yield "{$set}: pagination" => [$set, 'pagination', 'complex', false, false, false, 'none', [], [], 'A complex type that indicates pagination configuration options.'];
        yield "{$set}: pagination.cursor" => [$set, 'pagination.cursor', 'boolean', false, true, false, 'none', [], [], 'A Boolean value specifying support of cursor-based pagination.'];
        yield "{$set}: pagination.index" => [$set, 'pagination.index', 'boolean', false, true, false, 'none', [], [], 'A Boolean value specifying support of index-based pagination.'];
        yield "{$set}: pagination.defaultPaginationMethod" => [$set, 'pagination.defaultPaginationMethod', 'string', false, false, false, 'none', ['cursor', 'index'], [], 'A string value specifying the type of pagination that the service provider defaults to when the client has not specified which method it wishes to use. Possible values are "cursor" and "index".'];
        yield "{$set}: pagination.defaultPageSize" => [$set, 'pagination.defaultPageSize', 'integer', false, false, false, 'none', [], [], 'Positive integer value specifying the default number of results returned in a page when a count is not specified in the query.'];
        yield "{$set}: pagination.maxPageSize" => [$set, 'pagination.maxPageSize', 'integer', false, false, false, 'none', [], [], 'Positive integer specifying the maximum number of results returned in a page regardless of what is specified for the count in a query. The maximum number of results returned may be further restricted by other criteria.'];
        yield "{$set}: pagination.cursorTimeout" => [$set, 'pagination.cursorTimeout', 'integer', false, false, false, 'none', [], [], 'Positive integer specifying the minimum number of seconds that a cursor is valid between page requests. Clients waiting too long between cursor pagination requests may receive an invalid cursor error response. No value being specified may mean that there is no cursor timeout or that the cursor timeout is not a static duration.'];
    }

    /**
     * @return iterable<string, array{string, string, string, bool, bool, bool, string, list<string>, list<string>, string}>
     */
    private static function resourceType(): iterable
    {
        $set = 'ResourceType';
        yield "{$set}: name" => [$set, 'name', 'string', false, true, true, 'server', [], [], "The resource type name. When applicable, service providers MUST specify the name, e.g., 'User'."];
        yield "{$set}: description" => [$set, 'description', 'string', false, false, false, 'none', [], [], "The resource type's human-readable description. When applicable, service providers MUST specify the description."];
        yield "{$set}: endpoint" => [$set, 'endpoint', 'reference', false, true, true, 'server', [], ['uri'], "The resource type's HTTP-addressable endpoint relative to the Base URL, e.g., '/Users'."];
        yield "{$set}: schema" => [$set, 'schema', 'reference', false, true, true, 'none', [], ['uri'], "The resource type's primary/base schema URI."];
        yield "{$set}: schemaExtensions" => [$set, 'schemaExtensions', 'complex', true, false, false, 'none', [], [], "A list of URIs of the resource type's schema extensions."];
        yield "{$set}: schemaExtensions.schema" => [$set, 'schemaExtensions.schema', 'reference', false, true, true, 'none', [], ['uri'], 'The URI of a schema extension.'];
        yield "{$set}: schemaExtensions.required" => [$set, 'schemaExtensions.required', 'boolean', false, true, false, 'none', [], [], 'A Boolean value that specifies whether or not the schema extension is required for the resource type. If true, a resource of this type MUST include this schema extension and also include any attributes declared as required in this schema extension. If false, a resource of this type MAY omit this schema extension.'];
    }

    /**
     * The attribute rows of the Schema schema, for "attributes" and again for "attributes.subAttributes".
     *
     * @param list<string> $types
     *
     * @return iterable<string, array{string, string, string, bool, bool, bool, string, list<string>, list<string>, string}>
     */
    private static function schema(string $parent, array $types): iterable
    {
        $set = 'Schema';

        if ('attributes' === $parent) {
            yield "{$set}: name" => [$set, 'name', 'string', false, true, false, 'none', [], [], "The schema's human-readable name. When applicable, service providers MUST specify the name, e.g., 'User'."];
            yield "{$set}: description" => [$set, 'description', 'string', false, false, false, 'none', [], [], "The schema's human-readable description. When applicable, service providers MUST specify the description."];
            yield "{$set}: attributes" => [$set, 'attributes', 'complex', true, true, false, 'none', [], [], 'A complex attribute that includes the attributes of a schema.'];
        }

        $rows = [
            'name' => ['string', false, true, true, [], "The attribute's name."],
            'type' => ['string', false, true, false, $types, self::TYPE."'".implode("', '", $types)."'."],
            'multiValued' => ['boolean', false, true, false, [], "A Boolean value indicating an attribute's plurality."],
            'description' => ['string', false, false, true, [], 'A human-readable description of the attribute.'],
            'required' => ['boolean', false, false, false, [], 'A boolean value indicating whether or not the attribute is required.'],
            'canonicalValues' => ['string', true, false, true, [], "A collection of canonical values. When applicable, service providers MUST specify the canonical types, e.g., 'work', 'home'."],
            'caseExact' => ['boolean', false, false, false, [], 'A Boolean value indicating whether or not a string attribute is case sensitive.'],
            'mutability' => ['string', false, false, true, self::MUTABILITY, 'Indicates whether or not an attribute is modifiable.'],
            'returned' => ['string', false, false, true, self::RETURNED, 'Indicates when an attribute is returned in a response (e.g., to a query).'],
            'uniqueness' => ['string', false, false, true, self::UNIQUENESS, 'Indicates how unique a value must be.'],
            'referenceTypes' => ['string', true, false, true, [], "Used only with an attribute of type 'reference'. Specifies a SCIM resourceType that a reference attribute MAY refer to, e.g., 'User'."],
        ];

        foreach ($rows as $name => [$type, $multiValued, $required, $caseExact, $canonicalValues, $description]) {
            yield "{$set}: {$parent}.{$name}" => [$set, $parent.'.'.$name, $type, $multiValued, $required, $caseExact, 'none', $canonicalValues, [], $description];
        }

        if ('attributes' === $parent) {
            yield "{$set}: attributes.subAttributes" => [$set, 'attributes.subAttributes', 'complex', true, false, false, 'none', [], [], 'Used to define the sub-attributes of a complex attribute.'];
        }
    }

    private static function set(string $set): Schema
    {
        return match ($set) {
            'ServiceProviderConfig' => CoreSchemas::serviceProviderConfig(),
            'ResourceType' => CoreSchemas::resourceType(),
            default => CoreSchemas::schema(),
        };
    }

    /**
     * @param list<AttributeDefinition> $attributes
     * @param list<string>              $names
     */
    private static function definition(array $attributes, array $names): AttributeDefinition
    {
        $name = array_shift($names);
        $attribute = array_find($attributes, static fn (AttributeDefinition $attribute): bool => $attribute->name === $name);
        self::assertNotNull($attribute, (string) $name);

        return [] === $names ? $attribute : self::definition($attribute->subAttributes, $names);
    }

    /**
     * @param list<AttributeDefinition> $attributes
     *
     * @return list<string>
     */
    private static function paths(array $attributes, string $prefix = ''): array
    {
        return array_merge(...array_map(
            static fn (AttributeDefinition $attribute): array => [$prefix.$attribute->name, ...self::paths($attribute->subAttributes, $prefix.$attribute->name.'.')],
            $attributes,
        ));
    }
}
