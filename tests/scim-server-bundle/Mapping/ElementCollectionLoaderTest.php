<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\AttributeMapping;
use TacticMedia\ScimServerBundle\Mapping\ConstructorParameter;
use TacticMedia\ScimServerBundle\Mapping\ElementMapping;
use TacticMedia\ScimServerBundle\Mapping\MappingKind;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\UserAddress;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\Mailbox;

final class ElementCollectionLoaderTest extends KernelTestCase
{
    private const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';

    #[Test]
    #[TestDox('An element collection is the whole attribute, written through the adder and the remover')]
    public function compilesTheCollection(): void
    {
        $addresses = $this->member(new AttributeLoader()->load(User::class), 'addresses');

        self::assertSame(
            [MappingKind::ElementCollection, self::USER.':addresses', true, 'addAddress', 'removeAddress', UserAddress::class],
            [$addresses->kind, (string) $addresses->path, $addresses->writable, $addresses->adder, $addresses->remover, $addresses->element?->class],
        );
    }

    #[Test]
    #[TestDox('The properties of the element class map the sub-attributes in schema order, and the constructor gets the owner by type')]
    public function compilesTheElementClass(): void
    {
        $element = $this->element(User::class, 'addresses');

        self::assertSame(
            [['street', 'addresses.streetAddress', self::USER.':addresses.streetAddress'], ['locality', 'addresses.locality', self::USER.':addresses.locality'], ['postalCode', 'addresses.postalCode', self::USER.':addresses.postalCode'], ['country', 'addresses.country', self::USER.':addresses.country'], ['type', 'addresses.type', self::USER.':addresses.type'], ['primary', 'addresses.primary', self::USER.':addresses.primary']],
            array_map(static fn (AttributeMapping $attribute): array => [$attribute->member, $attribute->declaredPath, (string) $attribute->path], $element->attributes),
        );
        self::assertSame([MappingKind::Field, 'bool', false, true, false], [$element->attributes[5]->kind, $element->attributes[5]->phpType, $element->attributes[5]->nullable, $element->attributes[5]->writable, $element->attributes[5]->default]);
        self::assertSame(['user', [['type', 'addresses.type', true]]], [$element->ownerParameter, $this->parameters($element)]);
        self::assertSame($element->attributes[1], $element->attributeAt(new AttributePath(self::USER, 'addresses', 'LOCALITY')));
        self::assertNull($element->attributeAt(new AttributePath(self::USER, 'addresses', 'region')));
    }

    #[Test]
    #[TestDox('/Schemas publishes the mapped sub-attributes in schema order')]
    public function publishesTheMappedSubAttributes(): void
    {
        $addresses = new AttributeLoader()->load(User::class)->resourceType->schema->attribute('addresses');

        self::assertSame(['streetAddress', 'locality', 'postalCode', 'country', 'type', 'primary'], array_map(static fn (AttributeDefinition $sub): string => $sub->name, $addresses->subAttributes ?? []));
        self::assertSame(['work', 'home', 'other'], $addresses?->subAttribute('type')?->canonicalValues);
    }

    #[Test]
    #[TestDox('A member that is not nullable and has no default publishes a required sub-attribute')]
    public function notNullableWithoutDefaultIsRequired(): void
    {
        $mailbox = new AttributeLoader()->load(Mailbox::class);
        $emails = $mailbox->resourceType->schema->attribute('emails');
        $element = $this->element(Mailbox::class, 'emails');

        self::assertSame([true, false, false], [$emails?->subAttribute('value')?->required, $emails?->subAttribute('display')?->required, $emails?->subAttribute('primary')?->required]);
        self::assertTrue($element->attributeAt(new AttributePath(self::USER, 'emails', 'value'))?->required);
        self::assertSame(['mailbox', [['value', 'emails.value', false], ['label', null, true]]], [$element->ownerParameter, $this->parameters($element)]);
    }

    #[Test]
    #[TestDox('A method with #[ScimDqlExpression] is a read-only Computed mapping with the expression')]
    public function compilesTheDqlExpression(): void
    {
        $formatted = $this->member(new AttributeLoader()->load(User::class), 'getFormattedName');

        self::assertSame(
            [MappingKind::Computed, self::USER.':name.formatted', Mutability::ReadOnly, "TRIM(CONCAT(COALESCE({alias}.firstName, ''), ' ', COALESCE({alias}.lastName, '')))"],
            [$formatted->kind, (string) $formatted->path, $formatted->definition->mutability, $formatted->dqlExpression],
        );
        self::assertNull($this->member(new AttributeLoader()->load(User::class), 'displayName')->dqlExpression);
    }

    /**
     * @param class-string $class
     */
    private function element(string $class, string $member): ElementMapping
    {
        $element = $this->member(new AttributeLoader()->load($class), $member)->element;
        self::assertInstanceOf(ElementMapping::class, $element);

        return $element;
    }

    private function member(ResourceMapping $mapping, string $member): AttributeMapping
    {
        $found = array_find($mapping->attributes, static fn (AttributeMapping $attribute): bool => $attribute->member === $member);
        self::assertInstanceOf(AttributeMapping::class, $found);

        return $found;
    }

    /**
     * @return list<array{string, string|null, bool}>
     */
    private function parameters(ElementMapping $element): array
    {
        return array_map(static fn (ConstructorParameter $parameter): array => [$parameter->name, $parameter->attribute?->declaredPath, $parameter->optional], $element->constructorParameters);
    }
}
