<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\Group;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\AbstractEmail;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\BrokenEmail;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\DqlExpressionOnComplex;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\ElementAbstract;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\ElementAndSlot;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\ElementConstructorGetsNull;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\ElementConstructorUnresolvable;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\ElementDuplicate;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\ElementIsResource;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\ElementMemberErrors;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\ElementNotAClass;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\ElementOnMethod;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\ElementOnReference;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\ElementOnSubAttribute;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\ElementWithConverter;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\ElementWithoutMembers;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\NullableValueEmail;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\TenantEmail;
use TacticMedia\ScimServerBundle\Tests\Mapping\Fixtures\TwiceEmail;

final class ElementCollectionErrorTest extends KernelTestCase
{
    private const string ATTRIBUTE = 'has elementClass:, which needs a whole multi-valued complex attribute that does not refer to resources, for example "emails". Map such an attribute, or remove elementClass:.';
    private const string NOT_A_SUB_ATTRIBUTE = 'is not a sub-attribute of "emails". Use value, display, type or primary.';

    /**
     * @return iterable<string, array{class-string, list<string>}>
     */
    public static function invalidClasses(): iterable
    {
        yield 'a sub-attribute path' => [ElementOnSubAttribute::class, ['%s::$emails: "emails.value" '.self::ATTRIBUTE]];
        yield 'a reference attribute' => [ElementOnReference::class, ['%s::$groups: "groups" '.self::ATTRIBUTE]];
        yield 'a method' => [ElementOnMethod::class, ['%s::emails(): "emails" has elementClass:, which needs a property. Move elementClass: to a collection property with an adder and a remover.']];
        yield 'a converter' => [ElementWithConverter::class, ['%s::$emails: "emails" has converter: and elementClass:. Set converter: on the properties of the element class.']];
        yield 'no class' => [ElementNotAClass::class, ['%s::$emails: "emails" has elementClass: NoSuchEmail, which is not a class.']];
        yield 'a resource class' => [ElementIsResource::class, ['%s::$emails: "emails" has elementClass: '.Group::class.', which has #[ScimResource]. An element class has no #[ScimResource].']];
        yield 'an abstract class' => [ElementAbstract::class, ['%s::$emails: "emails" has elementClass: '.AbstractEmail::class.', which cannot be instantiated. Use a concrete class.']];
        yield 'no mapped member' => [ElementWithoutMembers::class, ['%s::$emails: "emails" has elementClass: stdClass, which maps no sub-attribute. Add #[ScimAttribute(\'<sub-attribute>\')] to its properties.']];
        yield 'a slot of the same attribute' => [ElementAndSlot::class, ['%s: $workEmail ("emails[type eq "work"].value") and $emails ("emails") map the same value. Map it once.']];
        yield 'the errors of each element member' => [ElementMemberErrors::class, [
            BrokenEmail::class.'::$value: "valu" '.self::NOT_A_SUB_ATTRIBUTE,
            BrokenEmail::class.'::$type: "type" is defined by RFC 7643, so its characteristics are fixed. Remove caseExact:.',
            BrokenEmail::class.'::$display: "display" has primary:, which applies only to a slot (attribute[type eq "<type>"].sub-attribute). Remove primary:.',
            BrokenEmail::class.'::$primary: "primary" is boolean, so default: must be a boolean value.',
            BrokenEmail::class.'::$label: "emails.display" '.self::NOT_A_SUB_ATTRIBUTE,
            BrokenEmail::class.'::display(): "display" is mapped by a method. An element class maps properties.',
        ]];
        yield 'two members of one sub-attribute' => [ElementDuplicate::class, [TwiceEmail::class.': $value ("value") and $address ("VALUE") map the same value. Map it once.']];
        yield 'an unresolvable constructor parameter' => [ElementConstructorUnresolvable::class, [TenantEmail::class.': cannot resolve the constructor parameter $tenant. Map a writable sub-attribute to a property named $tenant, type it with %s, or give it a default value.']];
        yield 'a constructor parameter that gets null' => [ElementConstructorGetsNull::class, [NullableValueEmail::class.': the constructor parameter $value gets null when "value" is absent. Make $value nullable, give it a default value, or set default: on "value".']];
        yield 'a DQL expression on a multi-valued attribute' => [DqlExpressionOnComplex::class, ['%s::emails(): "emails" has #[ScimDqlExpression], which needs a single-valued attribute that is not complex. Remove #[ScimDqlExpression].']];
    }

    /**
     * @param class-string $class
     * @param list<string> $errors
     */
    #[Test]
    #[DataProvider('invalidClasses')]
    #[TestDox('$_dataName fails the build')]
    public function invalidClassFails(string $class, array $errors): void
    {
        try {
            new AttributeLoader()->load($class);
            self::fail('No MappingException.');
        } catch (MappingException $mappingException) {
            self::assertSame(array_map(static fn (string $error): string => \sprintf($error, $class), $errors), $mappingException->errors);
        }
    }
}
