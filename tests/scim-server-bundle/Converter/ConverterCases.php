<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Converter;

use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServerBundle\Converter\ConversionContext;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\Device;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\IntUser;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\Person;

/**
 * Conversion contexts from the mappings of the fixture classes, by class short name and member.
 */
trait ConverterCases
{
    private const array CLASSES = ['User' => User::class, 'Device' => Device::class, 'IntUser' => IntUser::class, 'Person' => Person::class];

    /**
     * @param key-of<self::CLASSES> $class
     * @param string|null           $phpType   replaces the member type
     * @param bool|null             $caseExact replaces the caseExact characteristic
     */
    private static function context(string $class, string $member, ?string $phpType = null, ?bool $caseExact = null): ConversionContext
    {
        $resource = new AttributeLoader()->load(self::CLASSES[$class]);
        $attribute = array_find($resource->attributes, static fn (AttributeMapping $attribute): bool => $member === $attribute->member) ?? throw new \LogicException($member);

        if (null === $phpType && null === $caseExact) {
            return new ConversionContext($resource, $attribute);
        }

        $changed = self::changed($attribute, $phpType, $caseExact);

        return new ConversionContext(new ResourceMapping($resource->class, $resource->resourceType, $changed, [$changed], idPrefix: $resource->idPrefix), $changed);
    }

    private static function changed(AttributeMapping $attribute, ?string $phpType, ?bool $caseExact): AttributeMapping
    {
        $definition = $attribute->definition;

        return new AttributeMapping(
            path: $attribute->path,
            declaredPath: $attribute->declaredPath,
            definition: new AttributeDefinition($definition->name, $definition->type, caseExact: $caseExact ?? $definition->caseExact, mutability: $definition->mutability),
            kind: $attribute->kind,
            member: $attribute->member,
            method: false,
            phpType: $phpType ?? $attribute->phpType,
            nullable: $attribute->nullable,
            writable: $attribute->writable,
            required: $attribute->required,
        );
    }
}
