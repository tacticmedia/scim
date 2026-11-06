<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\MappingException;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\AbstractResource;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\AmbiguousName;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\AppSimpleAndSubAttribute;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\CollectionWithoutAdder;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\ComplexWithoutSubAttribute;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\ComputedTypeMismatch;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\ConstructorGetsNull;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\ConstructorGetsPassword;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\CustomTypeWithoutSchema;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\DefaultOfWrongType;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\DefaultOnReadOnly;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\DefaultOnReference;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\DefaultOnRequired;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\DuplicatePath;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\EmptyValidationGroups;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\ExtensionWithoutAttributes;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\FilterOnReference;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\FilterOnSingleValued;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\GetHookNotWritable;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\HashPasswordNotWriteOnly;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\HashPasswordWithoutInterface;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\IdPrefixEndsInDigit;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\IdPrefixOnUuid;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\ImmutableWithoutWriteAccess;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\InvalidEndpoint;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\InvalidPath;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\InvalidValueFilter;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\MembersWithoutType;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\MethodWithArguments;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\MethodWithFilter;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\MissingId;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\MissingRequired;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\MissingRequiredSubAttribute;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\MultiValuedWithoutFilter;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\NoScimResource;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\NotReadable;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\NotWritable;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\OptionalNotNullable;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\PasswordWithoutConverter;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\PrimaryOnField;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\PrivateSetNotWritable;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\ReservedEndpoint;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\RfcCharacteristic;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\RfcExtensionDeclared;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\ServerOwnedPath;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\SlotWithoutSubAttribute;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\TwoPrimarySlots;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UndeclaredUrn;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UniqueMethod;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UniqueMethodGroup;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UniquenessOnCommon;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UniqueSlot;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UniqueSubAttribute;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UniqueWriteOnly;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UnknownAttribute;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\UnresolvableConstructor;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\WholeAndSlot;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\WritableMethod;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\WrongCollectionType;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\WrongConverter;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\WrongFactory;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\WrongIdType;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\WrongPhpType;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\WrongReferenceType;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Invalid\WrongStore;

final class AttributeLoaderErrorTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{class-string, string}> the error with %s for the class name
     */
    public static function invalidClasses(): iterable
    {
        yield 'AbstractResource' => [AbstractResource::class, '%s: the class cannot be instantiated. Set #[ScimResource(factory: ...)].'];
        yield 'AppSimpleAndSubAttribute' => [AppSimpleAndSubAttribute::class, '%s::$badgeNumber: "urn:acme:scim:schemas:extension:acme:2.0:User:badge.number" is not an attribute of urn:ietf:params:scim:schemas:core:2.0:User or urn:ietf:params:scim:schemas:extension:enterprise:2.0:User or urn:acme:scim:schemas:extension:acme:2.0:User.'];
        yield 'AmbiguousName' => [AmbiguousName::class, '%s::$costCenter: "costCenter" is defined by urn:ietf:params:scim:schemas:extension:enterprise:2.0:User and urn:acme:scim:schemas:extension:acme:2.0:User. Qualify it with one of these schema URNs.'];
        yield 'CollectionWithoutAdder' => [CollectionWithoutAdder::class, '%s::$users: "members[type eq "User"]" is readWrite, but $users has no adder and remover. Add add<Element>() and remove<Element>() methods for $users.'];
        yield 'ComplexWithoutSubAttribute' => [ComplexWithoutSubAttribute::class, '%s::$name: "name" is complex. Map one sub-attribute, for example name.formatted.'];
        yield 'ConstructorGetsNull' => [ConstructorGetsNull::class, '%s: the constructor parameter $displayName gets null when "displayName" is absent. Make $displayName nullable, give it a default value, or set default: on "displayName".'];
        yield 'CustomTypeWithoutSchema' => [CustomTypeWithoutSchema::class, '%s: resource type "Device" is not defined by RFC 7643. Set #[ScimResource(\'Device\', schema: \'<core schema URN>\')].'];
        yield 'DefaultOfWrongType' => [DefaultOfWrongType::class, '%s::$enabled: "active" is boolean, so default: must be a boolean value.'];
        yield 'DefaultOnReadOnly' => [DefaultOnReadOnly::class, '%s::$createdAt: "meta.created" is readOnly, so default: has no effect. Remove default:.'];
        yield 'DefaultOnReference' => [DefaultOnReference::class, '%s::$manager: "manager" has default:, which applies only to a field or a slot. Remove default:.'];
        yield 'DefaultOnRequired' => [DefaultOnRequired::class, '%s::$userName: "userName" is required, so default: has no effect. Remove default:.'];
        yield 'DuplicatePath' => [DuplicatePath::class, '%s: $displayName ("displayName") and $label ("DISPLAYNAME") map the same value. Map it once.'];
        yield 'ExtensionWithoutAttributes' => [ExtensionWithoutAttributes::class, '%s: extension urn:acme:scim:schemas:extension:acme:2.0:User has no mapped attribute. Map one or remove it from extensions:.'];
        yield 'FilterOnReference' => [FilterOnReference::class, '%s::$manager: "manager[type eq "User"]" has a value filter, but only a multi-valued attribute takes one.'];
        yield 'FilterOnSingleValued' => [FilterOnSingleValued::class, '%s::$firstName: "name[type eq "x"].givenName" has a value filter, but "name" is not a multi-valued attribute with a type sub-attribute.'];
        yield 'IdPrefixOnUuid' => [IdPrefixOnUuid::class, '%s: idPrefix: needs an int id, $id is Symfony\\Component\\Uid\\Uuid. Remove idPrefix: or use an int id.'];
        yield 'ImmutableWithoutWriteAccess' => [ImmutableWithoutWriteAccess::class, '%s::$badge: "urn:acme:scim:schemas:extension:acme:2.0:User:badge" is immutable, but $badge has no setter. Add a setter or make $badge public.'];
        yield 'InvalidEndpoint' => [InvalidEndpoint::class, '%s: endpoint "Users" is not valid. Use "/" and one name, for example "/Devices".'];
        yield 'InvalidPath' => [InvalidPath::class, '%s::$firstName: "name..givenName" is not a valid path: \'name..givenName\' is not a valid path. Expected attrPath, attrPath[valFilter] or attrPath[valFilter].subAttr.'];
        yield 'InvalidValueFilter' => [InvalidValueFilter::class, '%s::$email: "emails[primary eq true].value" has a value filter that is not allowed in a mapping. Use [type eq "<value>"].'];
        yield 'MembersWithoutType' => [MembersWithoutType::class, '%s::$members: "members" refers to User and Group. Name one: members[type eq "User"].'];
        yield 'MethodWithArguments' => [MethodWithArguments::class, '%s::displayName(): "displayName" is mapped by a method that is not public or needs arguments. Make it public without required parameters.'];
        yield 'MethodWithFilter' => [MethodWithFilter::class, '%s::email(): "emails[type eq "work"].value" has a value filter. A method maps a path without [...].'];
        yield 'MissingId' => [MissingId::class, '%s: map "id" or add a property $id.'];
        yield 'MissingRequired' => [MissingRequired::class, '%s: "userName" is required and not mapped. Add #[ScimAttribute(\'userName\')] to a member.'];
        yield 'MissingRequiredSubAttribute' => [MissingRequiredSubAttribute::class, '%s: "manager.value" is required and not mapped. Add #[ScimAttribute(\'manager.value\')] to a member.'];
        yield 'MultiValuedWithoutFilter' => [MultiValuedWithoutFilter::class, '%s::$email: "emails.value" is multi-valued. Map one element: emails[type eq "<type>"].<sub-attribute>.'];
        yield 'NoScimResource' => [NoScimResource::class, '%s: the class has no #[ScimResource] attribute.'];
        yield 'NotReadable' => [NotReadable::class, '%s::$displayName: "displayName" is returned, but $displayName is not readable. Add a getter or make $displayName public.'];
        yield 'NotWritable' => [NotWritable::class, '%s::$displayName: "displayName" is readWrite, but $displayName has no setter. Add a setter or make $displayName public.'];
        yield 'OptionalNotNullable' => [OptionalNotNullable::class, '%s::$enabled: "active" is optional, so a create or replace without it writes null, but $enabled is not nullable. Set default: or make $enabled nullable.'];
        yield 'PasswordWithoutConverter' => [PasswordWithoutConverter::class, '%s::$password: "password" must be hashed: set converter: HashPassword::class.'];
        yield 'PrimaryOnField' => [PrimaryOnField::class, '%s::$displayName: "displayName" has primary:, which applies only to a slot (attribute[type eq "<type>"].sub-attribute). Remove primary:.'];
        yield 'ReservedEndpoint' => [ReservedEndpoint::class, '%s: endpoint "/Schemas" is reserved by RFC 7644. Set another endpoint:.'];
        yield 'RfcCharacteristic' => [RfcCharacteristic::class, '%s::$displayName: "displayName" is defined by RFC 7643, so its characteristics are fixed. Remove caseExact:, description:.'];
        yield 'RfcExtensionDeclared' => [RfcExtensionDeclared::class, '%s: extension urn:ietf:params:scim:schemas:extension:enterprise:2.0:User is the core schema, an RFC 7643 schema, or declared twice. Remove it from extensions:.'];
        yield 'ServerOwnedPath' => [ServerOwnedPath::class, '%s::$location: "meta.location" is set by the server and cannot be mapped.'];
        yield 'SlotWithoutSubAttribute' => [SlotWithoutSubAttribute::class, '%s::$email: "emails[type eq "work"]" maps no element value. Name one sub-attribute other than type and primary, for example emails[type eq "work"].value; set primary: for primary.'];
        yield 'TwoPrimarySlots' => [TwoPrimarySlots::class, '%s: "emails": only one slot group can be primary. Remove primary: from all types but one.'];
        yield 'UndeclaredUrn' => [UndeclaredUrn::class, '%s::$costCenter: "urn:acme:scim:schemas:extension:acme:2.0:User:costCenter" uses the schema urn:acme:scim:schemas:extension:acme:2.0:User, which is not declared. Add new ScimExtension(\'urn:acme:scim:schemas:extension:acme:2.0:User\', ...) to #[ScimResource(extensions: [...])].'];
        yield 'UniquenessOnCommon' => [UniquenessOnCommon::class, '%s::$externalId: "externalId" is defined by RFC 7643, so its characteristics are fixed. Remove uniqueness:.'];
        yield 'UnknownAttribute' => [UnknownAttribute::class, '%s::$firstName: "name.givenNam" is not an attribute of urn:ietf:params:scim:schemas:core:2.0:User or urn:ietf:params:scim:schemas:extension:enterprise:2.0:User. Did you mean "name.givenName"?'];
        yield 'UnresolvableConstructor' => [UnresolvableConstructor::class, '%s: cannot resolve the constructor parameter $tenant. Map a writable attribute to a property named $tenant, give the parameter a default value, or set #[ScimResource(factory: ...)].'];
        yield 'WholeAndSlot' => [WholeAndSlot::class, '%s: $email ("emails[type eq "work"].value") and emails() ("emails") map the same value. Map it once.'];
        yield 'WritableMethod' => [WritableMethod::class, '%s::score(): "urn:acme:scim:schemas:extension:acme:2.0:User:score" is mapped by a method, so it is readOnly. Remove mutability:.'];
        yield 'WrongCollectionType' => [WrongCollectionType::class, '%s::$groups: "groups" is multi-valued, the member is string. Use a Doctrine Collection, array or iterable.'];
        yield 'WrongConverter' => [WrongConverter::class, '%s::$displayName: "displayName" has converter: stdClass, which does not implement TacticMedia\\ScimServerBundle\\Converter\\ValueConverter.'];
        yield 'WrongFactory' => [WrongFactory::class, '%s: factory: stdClass does not implement TacticMedia\\ScimServerBundle\\Object\\ObjectFactory.'];
        yield 'WrongIdType' => [WrongIdType::class, '%s::$id: "id" needs a string, an int or a Symfony\\Component\\Uid\\AbstractUid member, the member is float.'];
        yield 'WrongPhpType' => [WrongPhpType::class, '%s::$enabled: "active" is boolean, the member is ?string. Use bool or set converter:.'];
        yield 'WrongReferenceType' => [WrongReferenceType::class, '%s::$manager: "manager" refers to User, but the member is ?stdClass, which has no #[ScimResource(\'User\')]. Type it with the class that maps User.'];
        yield 'WrongStore' => [WrongStore::class, '%s: store: stdClass does not implement TacticMedia\\ScimServerBundle\\Object\\ObjectStore.'];
        yield 'PrivateSetNotWritable' => [PrivateSetNotWritable::class, '%s::$displayName: "displayName" is readWrite, but $displayName has no setter. Add a setter or make $displayName public.'];
        yield 'GetHookNotWritable' => [GetHookNotWritable::class, '%s::$displayName: "displayName" is readWrite, but $displayName has no setter. Add a setter or make $displayName public.'];
        yield 'ConstructorGetsPassword' => [ConstructorGetsPassword::class, '%s: the constructor parameter $password cannot get the writeOnly "password", which is written after the constructor. Make $password nullable, give it a default value, or set #[ScimResource(factory: ...)].'];
        yield 'UniqueMethod' => [UniqueMethod::class, '%s::displayName(): "displayName" is unique, so the server checks the value of each write, but a method mapping is readOnly and no client writes it. Map a property, or remove uniqueness:.'];
        yield 'UniqueMethodGroup' => [UniqueMethodGroup::class, '%s::displayName(): "displayName" is unique, so the server checks the value of each write, but a method mapping is readOnly and no client writes it. Map a property, or set uniqueness: Uniqueness::None.'];
        yield 'UniqueWriteOnly' => [UniqueWriteOnly::class, '%s::$pin: "urn:acme:scim:schemas:extension:acme:2.0:User:pin" is unique, so the server checks it with an "eq" filter, but a writeOnly attribute cannot be filtered. Map an attribute that is not writeOnly, or remove uniqueness:.'];
        yield 'UniqueSubAttribute' => [UniqueSubAttribute::class, '%s::$firstName: "name.givenName" has uniqueness:, which the server checks only on a single-valued attribute that is not complex. Remove uniqueness:.'];
        yield 'UniqueSlot' => [UniqueSlot::class, '%s::$email: "emails[type eq "work"].value" has uniqueness:, which the server checks only on a single-valued attribute that is not complex. Remove uniqueness:.'];
        yield 'HashPasswordWithoutInterface' => [HashPasswordWithoutInterface::class, '%1$s::$password: "password" uses HashPassword, so %1$s must implement Symfony\\Component\\Security\\Core\\User\\PasswordAuthenticatedUserInterface.'];
        yield 'HashPasswordNotWriteOnly' => [HashPasswordNotWriteOnly::class, '%s::$pin: "urn:acme:scim:schemas:extension:acme:2.0:User:pin" uses HashPassword, so it must be writeOnly, like password, but it is readWrite. Remove converter:, or set mutability: Mutability::WriteOnly on an application attribute.'];
        yield 'ComputedTypeMismatch' => [ComputedTypeMismatch::class, '%s::active(): "active" is boolean, the member is string. Use bool or set converter:.'];
        yield 'IdPrefixEndsInDigit' => [IdPrefixEndsInDigit::class, '%s: idPrefix: "u1" ends in a digit, so an id can equal the id of another prefix: "u1" + 5 = "u" + 15. End idPrefix: with a character that is not a digit.'];
        yield 'EmptyValidationGroups' => [EmptyValidationGroups::class, "%s: validationGroups: [] names no group. Set validationGroups: ['Default'], or remove validationGroups: to turn the validation off."];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('invalidClasses')]
    #[TestDox('$class fails with one error that names the fix')]
    public function invalidClassFailsWithOneError(string $class, string $error): void
    {
        try {
            new AttributeLoader()->load($class);
            self::fail('No MappingException.');
        } catch (MappingException $mappingException) {
            self::assertSame([\sprintf($error, $class)], $mappingException->errors);
            self::assertSame(\sprintf($error, $class), $mappingException->getMessage());
        }
    }
}
