<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\CoreSchemas;

/**
 * The User, Group and EnterpriseUser rows are RFC 7643 section 8.7.1 with errata 5368, 6001, 6004, 6005, 8358, 8359,
 * 8471 and 8472, and Group members.value caseExact and displayName uniqueness server. The common rows are RFC 7643
 * section 3.1.
 */
final class CoreSchemasTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string, string, bool, bool, bool, string, string, string, list<string>, list<string>}>
     */
    public static function characteristics(): iterable
    {
        yield 'User: userName' => ['User', 'userName', 'string', false, true, false, 'readWrite', 'default', 'server', [], []];
        yield 'User: name' => ['User', 'name', 'complex', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: name.formatted' => ['User', 'name.formatted', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: name.familyName' => ['User', 'name.familyName', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: name.givenName' => ['User', 'name.givenName', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: name.middleName' => ['User', 'name.middleName', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: name.honorificPrefix' => ['User', 'name.honorificPrefix', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: name.honorificSuffix' => ['User', 'name.honorificSuffix', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: displayName' => ['User', 'displayName', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: nickName' => ['User', 'nickName', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: profileUrl' => ['User', 'profileUrl', 'reference', false, false, true, 'readWrite', 'default', 'none', [], ['external']];
        yield 'User: title' => ['User', 'title', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: userType' => ['User', 'userType', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: preferredLanguage' => ['User', 'preferredLanguage', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: locale' => ['User', 'locale', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: timezone' => ['User', 'timezone', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: active' => ['User', 'active', 'boolean', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: password' => ['User', 'password', 'string', false, false, false, 'writeOnly', 'never', 'none', [], []];
        yield 'User: emails' => ['User', 'emails', 'complex', true, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: emails.value' => ['User', 'emails.value', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: emails.display' => ['User', 'emails.display', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: emails.type' => ['User', 'emails.type', 'string', false, false, false, 'readWrite', 'default', 'none', ['work', 'home', 'other'], []];
        yield 'User: emails.primary' => ['User', 'emails.primary', 'boolean', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: phoneNumbers' => ['User', 'phoneNumbers', 'complex', true, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: phoneNumbers.value' => ['User', 'phoneNumbers.value', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: phoneNumbers.display' => ['User', 'phoneNumbers.display', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: phoneNumbers.type' => ['User', 'phoneNumbers.type', 'string', false, false, false, 'readWrite', 'default', 'none', ['work', 'home', 'mobile', 'fax', 'pager', 'other'], []];
        yield 'User: phoneNumbers.primary' => ['User', 'phoneNumbers.primary', 'boolean', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: ims' => ['User', 'ims', 'complex', true, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: ims.value' => ['User', 'ims.value', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: ims.display' => ['User', 'ims.display', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: ims.type' => ['User', 'ims.type', 'string', false, false, false, 'readWrite', 'default', 'none', ['aim', 'gtalk', 'icq', 'xmpp', 'msn', 'skype', 'qq', 'yahoo'], []];
        yield 'User: ims.primary' => ['User', 'ims.primary', 'boolean', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: photos' => ['User', 'photos', 'complex', true, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: photos.value' => ['User', 'photos.value', 'reference', false, false, true, 'readWrite', 'default', 'none', [], ['external']];
        yield 'User: photos.display' => ['User', 'photos.display', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: photos.type' => ['User', 'photos.type', 'string', false, false, false, 'readWrite', 'default', 'none', ['photo', 'thumbnail'], []];
        yield 'User: photos.primary' => ['User', 'photos.primary', 'boolean', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: addresses' => ['User', 'addresses', 'complex', true, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: addresses.formatted' => ['User', 'addresses.formatted', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: addresses.streetAddress' => ['User', 'addresses.streetAddress', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: addresses.locality' => ['User', 'addresses.locality', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: addresses.region' => ['User', 'addresses.region', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: addresses.postalCode' => ['User', 'addresses.postalCode', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: addresses.country' => ['User', 'addresses.country', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: addresses.type' => ['User', 'addresses.type', 'string', false, false, false, 'readWrite', 'default', 'none', ['work', 'home', 'other'], []];
        yield 'User: addresses.primary' => ['User', 'addresses.primary', 'boolean', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: groups' => ['User', 'groups', 'complex', true, false, false, 'readOnly', 'default', 'none', [], []];
        yield 'User: groups.value' => ['User', 'groups.value', 'string', false, false, false, 'readOnly', 'default', 'none', [], []];
        yield 'User: groups.$ref' => ['User', 'groups.$ref', 'reference', false, false, true, 'readOnly', 'default', 'none', [], ['Group']];
        yield 'User: groups.display' => ['User', 'groups.display', 'string', false, false, false, 'readOnly', 'default', 'none', [], []];
        yield 'User: groups.type' => ['User', 'groups.type', 'string', false, false, false, 'readOnly', 'default', 'none', ['direct', 'indirect'], []];
        yield 'User: entitlements' => ['User', 'entitlements', 'complex', true, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: entitlements.value' => ['User', 'entitlements.value', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: entitlements.display' => ['User', 'entitlements.display', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: entitlements.type' => ['User', 'entitlements.type', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: entitlements.primary' => ['User', 'entitlements.primary', 'boolean', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: roles' => ['User', 'roles', 'complex', true, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: roles.value' => ['User', 'roles.value', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: roles.display' => ['User', 'roles.display', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: roles.type' => ['User', 'roles.type', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: roles.primary' => ['User', 'roles.primary', 'boolean', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: x509Certificates' => ['User', 'x509Certificates', 'complex', true, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: x509Certificates.value' => ['User', 'x509Certificates.value', 'binary', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: x509Certificates.display' => ['User', 'x509Certificates.display', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: x509Certificates.type' => ['User', 'x509Certificates.type', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'User: x509Certificates.primary' => ['User', 'x509Certificates.primary', 'boolean', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'Group: displayName' => ['Group', 'displayName', 'string', false, true, false, 'readWrite', 'default', 'server', [], []];
        yield 'Group: members' => ['Group', 'members', 'complex', true, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'Group: members.value (ids are caseExact)' => ['Group', 'members.value', 'string', false, false, true, 'immutable', 'default', 'none', [], []];
        yield 'Group: members.$ref' => ['Group', 'members.$ref', 'reference', false, false, false, 'immutable', 'default', 'none', [], ['User', 'Group']];
        yield 'Group: members.display (erratum 6011)' => ['Group', 'members.display', 'string', false, false, false, 'readOnly', 'default', 'none', [], []];
        yield 'Group: members.type' => ['Group', 'members.type', 'string', false, false, false, 'immutable', 'default', 'none', ['User', 'Group'], []];
        yield 'EnterpriseUser: employeeNumber' => ['EnterpriseUser', 'employeeNumber', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'EnterpriseUser: costCenter' => ['EnterpriseUser', 'costCenter', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'EnterpriseUser: organization' => ['EnterpriseUser', 'organization', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'EnterpriseUser: division' => ['EnterpriseUser', 'division', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'EnterpriseUser: department' => ['EnterpriseUser', 'department', 'string', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'EnterpriseUser: manager' => ['EnterpriseUser', 'manager', 'complex', false, false, false, 'readWrite', 'default', 'none', [], []];
        yield 'EnterpriseUser: manager.value' => ['EnterpriseUser', 'manager.value', 'string', false, true, true, 'readWrite', 'default', 'none', [], []];
        yield 'EnterpriseUser: manager.$ref (not required)' => ['EnterpriseUser', 'manager.$ref', 'reference', false, false, false, 'readWrite', 'default', 'none', [], ['User']];
        yield 'EnterpriseUser: manager.displayName' => ['EnterpriseUser', 'manager.displayName', 'string', false, false, false, 'readOnly', 'default', 'none', [], []];
        yield 'common: id' => ['common', 'id', 'string', false, false, true, 'readOnly', 'always', 'server', [], []];
        yield 'common: externalId' => ['common', 'externalId', 'string', false, false, true, 'readWrite', 'default', 'none', [], []];
        yield 'common: meta' => ['common', 'meta', 'complex', false, false, false, 'readOnly', 'default', 'none', [], []];
        yield 'common: meta.resourceType' => ['common', 'meta.resourceType', 'string', false, false, true, 'readOnly', 'default', 'none', [], []];
        yield 'common: meta.created' => ['common', 'meta.created', 'dateTime', false, false, false, 'readOnly', 'default', 'none', [], []];
        yield 'common: meta.lastModified' => ['common', 'meta.lastModified', 'dateTime', false, false, false, 'readOnly', 'default', 'none', [], []];
        yield 'common: meta.location' => ['common', 'meta.location', 'reference', false, false, true, 'readOnly', 'default', 'none', [], ['uri']];
        yield 'common: meta.version' => ['common', 'meta.version', 'string', false, false, true, 'readOnly', 'default', 'none', [], []];
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function descriptions(): iterable
    {
        yield 'User: userName' => ['User', 'userName', "Unique identifier for the User, typically used by the user to directly authenticate to the service provider. Each User MUST include a non-empty userName value. This identifier MUST be unique across the service provider's entire set of Users. REQUIRED."];
        yield 'User: name' => ['User', 'name', "The components of the user's real name. Providers MAY return just the full name as a single string in the formatted sub-attribute, or they MAY return just the individual component attributes using the other sub-attributes, or they MAY return both. If both variants are returned, they SHOULD be describing the same name, with the formatted name indicating how the component attributes should be combined."];
        yield 'User: name.formatted' => ['User', 'name.formatted', "The full name, including all middle names, titles, and suffixes as appropriate, formatted for display (e.g., 'Ms. Barbara J Jensen, III')."];
        yield 'User: name.familyName' => ['User', 'name.familyName', "The family name of the User, or last name in most Western languages (e.g., 'Jensen' given the full name 'Ms. Barbara J Jensen, III')."];
        yield 'User: name.givenName' => ['User', 'name.givenName', "The given name of the User, or first name in most Western languages (e.g., 'Barbara' given the full name 'Ms. Barbara J Jensen, III')."];
        yield 'User: name.middleName' => ['User', 'name.middleName', "The middle name(s) of the User (e.g., 'Jane' given the full name 'Ms. Barbara J Jensen, III')."];
        yield 'User: name.honorificPrefix' => ['User', 'name.honorificPrefix', "The honorific prefix(es) of the User, or title in most Western languages (e.g., 'Ms.' given the full name 'Ms. Barbara J Jensen, III')."];
        yield 'User: name.honorificSuffix' => ['User', 'name.honorificSuffix', "The honorific suffix(es) of the User, or suffix in most Western languages (e.g., 'III' given the full name 'Ms. Barbara J Jensen, III')."];
        yield 'User: displayName' => ['User', 'displayName', 'The name of the User, suitable for display to end-users. The name SHOULD be the full name of the User being described, if known.'];
        yield 'User: nickName' => ['User', 'nickName', "The casual way to address the user in real life, e.g., 'Bob' or 'Bobby' instead of 'Robert'. This attribute SHOULD NOT be used to represent a User's username (e.g., 'bjensen' or 'mpepperidge')."];
        yield 'User: profileUrl' => ['User', 'profileUrl', "A fully qualified URL pointing to a page representing the User's online profile."];
        yield 'User: title' => ['User', 'title', 'The user\'s title, such as "Vice President."'];
        yield 'User: userType' => ['User', 'userType', "Used to identify the relationship between the organization and the user. Typical values used might be 'Contractor', 'Employee', 'Intern', 'Temp', 'External', and 'Unknown', but any value may be used."];
        yield 'User: preferredLanguage' => ['User', 'preferredLanguage', "Indicates the User's preferred written or spoken language. Generally used for selecting a localized user interface; e.g., 'en_US' specifies the language English and country US."];
        yield 'User: locale' => ['User', 'locale', "Used to indicate the User's default location for purposes of localizing items such as currency, date time format, or numerical representations."];
        yield 'User: timezone' => ['User', 'timezone', "The User's time zone in the 'Olson' time zone database format, e.g., 'America/Los_Angeles'."];
        yield 'User: active' => ['User', 'active', "A Boolean value indicating the User's administrative status."];
        yield 'User: password' => ['User', 'password', "The User's cleartext password. This attribute is intended to be used as a means to specify an initial password when creating a new User or to reset an existing User's password."];
        yield 'User: emails' => ['User', 'emails', "Email addresses for the user. The value SHOULD be canonicalized by the service provider, e.g., 'bjensen@example.com' instead of 'bjensen@EXAMPLE.COM'. Canonical type values of 'work', 'home', and 'other'."];
        yield 'User: emails.value' => ['User', 'emails.value', "Email addresses for the user. The value SHOULD be canonicalized by the service provider, e.g., 'bjensen@example.com' instead of 'bjensen@EXAMPLE.COM'. Canonical type values of 'work', 'home', and 'other'."];
        yield 'User: emails.display' => ['User', 'emails.display', 'A human-readable name, primarily used for display purposes.'];
        yield 'User: emails.type' => ['User', 'emails.type', "A label indicating the attribute's function, e.g., 'work' or 'home'."];
        yield 'User: emails.primary' => ['User', 'emails.primary', "A Boolean value indicating the 'primary' or preferred attribute value for this attribute, e.g., the preferred mailing address or primary email address. The primary attribute value 'true' MUST appear no more than once."];
        yield 'User: phoneNumbers' => ['User', 'phoneNumbers', "Phone numbers for the User. The value SHOULD be canonicalized by the service provider according to the format specified in RFC 3966, e.g., 'tel:+1-201-555-0123'. Canonical type values of 'work', 'home', 'mobile', 'fax', 'pager', and 'other'."];
        yield 'User: phoneNumbers.value' => ['User', 'phoneNumbers.value', 'Phone number of the User.'];
        yield 'User: phoneNumbers.display' => ['User', 'phoneNumbers.display', 'A human-readable name, primarily used for display purposes.'];
        yield 'User: phoneNumbers.type' => ['User', 'phoneNumbers.type', "A label indicating the attribute's function, e.g., 'work', 'home', 'mobile'."];
        yield 'User: phoneNumbers.primary' => ['User', 'phoneNumbers.primary', "A Boolean value indicating the 'primary' or preferred attribute value for this attribute, e.g., the preferred phone number or primary phone number. The primary attribute value 'true' MUST appear no more than once."];
        yield 'User: ims' => ['User', 'ims', 'Instant messaging addresses for the User.'];
        yield 'User: ims.value' => ['User', 'ims.value', 'Instant messaging address for the User.'];
        yield 'User: ims.display' => ['User', 'ims.display', 'A human-readable name, primarily used for display purposes.'];
        yield 'User: ims.type' => ['User', 'ims.type', "A label indicating the attribute's function, e.g., 'aim', 'gtalk', 'xmpp'."];
        yield 'User: ims.primary' => ['User', 'ims.primary', "A Boolean value indicating the 'primary' or preferred attribute value for this attribute, e.g., the preferred messenger or primary messenger. The primary attribute value 'true' MUST appear no more than once."];
        yield 'User: photos' => ['User', 'photos', 'URLs of photos of the User.'];
        yield 'User: photos.value' => ['User', 'photos.value', 'URL of a photo of the User.'];
        yield 'User: photos.display' => ['User', 'photos.display', 'A human-readable name, primarily used for display purposes.'];
        yield 'User: photos.type' => ['User', 'photos.type', "A label indicating the attribute's function, i.e., 'photo' or 'thumbnail'."];
        yield 'User: photos.primary' => ['User', 'photos.primary', "A Boolean value indicating the 'primary' or preferred attribute value for this attribute, e.g., the preferred photo or thumbnail. The primary attribute value 'true' MUST appear no more than once."];
        yield 'User: addresses' => ['User', 'addresses', "A physical mailing address for this User. Canonical type values of 'work', 'home', and 'other'. This attribute is a complex type with the following sub-attributes."];
        yield 'User: addresses.formatted' => ['User', 'addresses.formatted', 'The full mailing address, formatted for display or use with a mailing label. This attribute MAY contain newlines.'];
        yield 'User: addresses.streetAddress' => ['User', 'addresses.streetAddress', 'The full street address component, which may include house number, street name, P.O. box, and multi-line extended street address information. This attribute MAY contain newlines.'];
        yield 'User: addresses.locality' => ['User', 'addresses.locality', 'The city or locality component.'];
        yield 'User: addresses.region' => ['User', 'addresses.region', 'The state or region component.'];
        yield 'User: addresses.postalCode' => ['User', 'addresses.postalCode', 'The zip code or postal code component.'];
        yield 'User: addresses.country' => ['User', 'addresses.country', 'The country name component.'];
        yield 'User: addresses.type' => ['User', 'addresses.type', "A label indicating the attribute's function, e.g., 'work' or 'home'."];
        yield 'User: addresses.primary' => ['User', 'addresses.primary', "A Boolean value indicating the 'primary' or preferred attribute value for this attribute, e.g., the preferred mailing address. The primary attribute value 'true' MUST appear no more than once."];
        yield 'User: groups' => ['User', 'groups', 'A list of groups to which the user belongs, either through direct membership, through nested groups, or dynamically calculated.'];
        yield 'User: groups.value' => ['User', 'groups.value', "The identifier of the User's group."];
        yield 'User: groups.$ref' => ['User', 'groups.$ref', "The URI of the corresponding 'Group' resource to which the user belongs."];
        yield 'User: groups.display' => ['User', 'groups.display', 'A human-readable name, primarily used for display purposes. READ-ONLY.'];
        yield 'User: groups.type' => ['User', 'groups.type', "A label indicating the attribute's function, e.g., 'direct' or 'indirect'."];
        yield 'User: entitlements' => ['User', 'entitlements', 'A list of entitlements for the User that represent a thing the User has.'];
        yield 'User: entitlements.value' => ['User', 'entitlements.value', 'The value of an entitlement.'];
        yield 'User: entitlements.display' => ['User', 'entitlements.display', 'A human-readable name, primarily used for display purposes.'];
        yield 'User: entitlements.type' => ['User', 'entitlements.type', "A label indicating the attribute's function."];
        yield 'User: entitlements.primary' => ['User', 'entitlements.primary', "A Boolean value indicating the 'primary' or preferred attribute value for this attribute. The primary attribute value 'true' MUST appear no more than once."];
        yield 'User: roles' => ['User', 'roles', "A list of roles for the User that collectively represent who the User is, e.g., 'Student', 'Faculty'."];
        yield 'User: roles.value' => ['User', 'roles.value', 'The value of a role.'];
        yield 'User: roles.display' => ['User', 'roles.display', 'A human-readable name, primarily used for display purposes.'];
        yield 'User: roles.type' => ['User', 'roles.type', "A label indicating the attribute's function."];
        yield 'User: roles.primary' => ['User', 'roles.primary', "A Boolean value indicating the 'primary' or preferred attribute value for this attribute. The primary attribute value 'true' MUST appear no more than once."];
        yield 'User: x509Certificates' => ['User', 'x509Certificates', 'A list of certificates issued to the User.'];
        yield 'User: x509Certificates.value' => ['User', 'x509Certificates.value', 'The value of an X.509 certificate.'];
        yield 'User: x509Certificates.display' => ['User', 'x509Certificates.display', 'A human-readable name, primarily used for display purposes.'];
        yield 'User: x509Certificates.type' => ['User', 'x509Certificates.type', "A label indicating the attribute's function."];
        yield 'User: x509Certificates.primary' => ['User', 'x509Certificates.primary', "A Boolean value indicating the 'primary' or preferred attribute value for this attribute. The primary attribute value 'true' MUST appear no more than once."];
        yield 'Group: displayName' => ['Group', 'displayName', 'A human-readable name for the Group. REQUIRED.'];
        yield 'Group: members' => ['Group', 'members', 'A list of members of the Group.'];
        yield 'Group: members.value' => ['Group', 'members.value', 'Identifier of the member of this Group.'];
        yield 'Group: members.$ref' => ['Group', 'members.$ref', 'The URI corresponding to a SCIM resource that is a member of this Group.'];
        yield 'Group: members.display (erratum 6011)' => ['Group', 'members.display', 'A human-readable name, primarily used for display purposes.'];
        yield 'Group: members.type' => ['Group', 'members.type', "A label indicating the type of resource, e.g., 'User' or 'Group'."];
        yield 'EnterpriseUser: employeeNumber' => ['EnterpriseUser', 'employeeNumber', 'Numeric or alphanumeric identifier assigned to a person, typically based on order of hire or association with an organization.'];
        yield 'EnterpriseUser: costCenter' => ['EnterpriseUser', 'costCenter', 'Identifies the name of a cost center.'];
        yield 'EnterpriseUser: organization' => ['EnterpriseUser', 'organization', 'Identifies the name of an organization.'];
        yield 'EnterpriseUser: division' => ['EnterpriseUser', 'division', 'Identifies the name of a division.'];
        yield 'EnterpriseUser: department' => ['EnterpriseUser', 'department', 'Identifies the name of a department.'];
        yield 'EnterpriseUser: manager' => ['EnterpriseUser', 'manager', "The User's manager. A complex type that optionally allows service providers to represent organizational hierarchy by referencing the 'id' attribute of another User."];
        yield 'EnterpriseUser: manager.value' => ['EnterpriseUser', 'manager.value', "The id of the SCIM resource representing the User's manager. REQUIRED."];
        yield 'EnterpriseUser: manager.$ref (not required)' => ['EnterpriseUser', 'manager.$ref', "The URI of the SCIM resource representing the User's manager."];
        yield 'EnterpriseUser: manager.displayName' => ['EnterpriseUser', 'manager.displayName', "The displayName of the User's manager. OPTIONAL and READ-ONLY."];
        yield 'common: id' => ['common', 'id', 'A unique identifier for a SCIM resource as defined by the service provider.'];
        yield 'common: externalId' => ['common', 'externalId', 'A String that is an identifier for the resource as defined by the provisioning client.'];
        yield 'common: meta' => ['common', 'meta', 'A complex attribute containing resource metadata.'];
        yield 'common: meta.resourceType' => ['common', 'meta.resourceType', 'The name of the resource type of the resource.'];
        yield 'common: meta.created' => ['common', 'meta.created', 'The "DateTime" that the resource was added to the service provider.'];
        yield 'common: meta.lastModified' => ['common', 'meta.lastModified', 'The most recent DateTime that the details of this resource were updated at the service provider.'];
        yield 'common: meta.location' => ['common', 'meta.location', 'The URI of the resource being returned.'];
        yield 'common: meta.version' => ['common', 'meta.version', 'The version of the resource being returned.'];
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function schemas(): iterable
    {
        yield 'User' => ['User', 'urn:ietf:params:scim:schemas:core:2.0:User', 'User', 'User Account'];
        yield 'Group' => ['Group', 'urn:ietf:params:scim:schemas:core:2.0:Group', 'Group', 'Group'];
        yield 'EnterpriseUser' => ['EnterpriseUser', 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User', 'EnterpriseUser', 'Enterprise User'];
    }

    /**
     * @param list<string> $canonicalValues
     * @param list<string> $referenceTypes
     */
    #[Test]
    #[DataProvider('characteristics')]
    #[TestDox('$set $path has the RFC characteristics')]
    public function attributeHasTheRfcCharacteristics(string $set, string $path, string $type, bool $multiValued, bool $required, bool $caseExact, string $mutability, string $returned, string $uniqueness, array $canonicalValues, array $referenceTypes): void
    {
        $definition = $this->definition($set, $path);

        self::assertSame(
            [$type, $multiValued, $required, $caseExact, $mutability, $returned, $uniqueness, $canonicalValues, $referenceTypes],
            [$definition->type->value, $definition->multiValued, $definition->required, $definition->caseExact, $definition->mutability->value, $definition->returned->value, $definition->uniqueness->value, $definition->canonicalValues, $definition->referenceTypes],
        );
    }

    #[Test]
    #[DataProvider('descriptions')]
    #[TestDox('$set $path has the RFC description')]
    public function attributeHasTheRfcDescription(string $set, string $path, string $description): void
    {
        self::assertSame($description, $this->definition($set, $path)->description);
    }

    #[Test]
    #[TestDox('Each attribute set holds exactly the table rows, in RFC order')]
    public function eachSetHoldsExactlyTheTableRows(): void
    {
        $expected = [];

        foreach (self::characteristics() as [$set, $path]) {
            $expected[$set][] = $path;
        }

        self::assertSame($expected, array_map(self::paths(...), [
            'User' => CoreSchemas::user()->attributes,
            'Group' => CoreSchemas::group()->attributes,
            'EnterpriseUser' => CoreSchemas::enterpriseUser()->attributes,
            'common' => CoreSchemas::commonAttributes(),
        ]));
    }

    #[Test]
    #[DataProvider('schemas')]
    #[TestDox('The $set schema has the RFC id, name and description')]
    public function schemaHasTheRfcIdNameAndDescription(string $set, string $id, string $name, string $description): void
    {
        $schema = match ($set) {
            'User' => CoreSchemas::user(),
            'Group' => CoreSchemas::group(),
            default => CoreSchemas::enterpriseUser(),
        };

        self::assertSame([$id, $name, $description], [$schema->id, $schema->name, $schema->description]);
    }

    #[Test]
    #[TestDox('The Group schema serializes every characteristic explicitly')]
    public function groupSchemaSerializesEveryCharacteristic(): void
    {
        self::assertSame([
            'id' => 'urn:ietf:params:scim:schemas:core:2.0:Group',
            'name' => 'Group',
            'description' => 'Group',
            'attributes' => [
                ['name' => 'displayName', 'type' => 'string', 'multiValued' => false, 'description' => 'A human-readable name for the Group. REQUIRED.', 'required' => true, 'caseExact' => false, 'mutability' => 'readWrite', 'returned' => 'default', 'uniqueness' => 'server'],
                [
                    'name' => 'members', 'type' => 'complex', 'multiValued' => true, 'description' => 'A list of members of the Group.', 'required' => false, 'caseExact' => false, 'mutability' => 'readWrite', 'returned' => 'default', 'uniqueness' => 'none',
                    'subAttributes' => [
                        ['name' => 'value', 'type' => 'string', 'multiValued' => false, 'description' => 'Identifier of the member of this Group.', 'required' => false, 'caseExact' => true, 'mutability' => 'immutable', 'returned' => 'default', 'uniqueness' => 'none'],
                        ['name' => '$ref', 'type' => 'reference', 'multiValued' => false, 'description' => 'The URI corresponding to a SCIM resource that is a member of this Group.', 'required' => false, 'caseExact' => false, 'mutability' => 'immutable', 'returned' => 'default', 'uniqueness' => 'none', 'referenceTypes' => ['User', 'Group']],
                        ['name' => 'display', 'type' => 'string', 'multiValued' => false, 'description' => 'A human-readable name, primarily used for display purposes.', 'required' => false, 'caseExact' => false, 'mutability' => 'readOnly', 'returned' => 'default', 'uniqueness' => 'none'],
                        ['name' => 'type', 'type' => 'string', 'multiValued' => false, 'description' => "A label indicating the type of resource, e.g., 'User' or 'Group'.", 'required' => false, 'caseExact' => false, 'mutability' => 'immutable', 'returned' => 'default', 'uniqueness' => 'none', 'canonicalValues' => ['User', 'Group']],
                    ],
                ],
            ],
        ], CoreSchemas::group()->toArray());
    }

    private function definition(string $set, string $path): AttributeDefinition
    {
        $attributes = match ($set) {
            'User' => CoreSchemas::user()->attributes,
            'Group' => CoreSchemas::group()->attributes,
            'EnterpriseUser' => CoreSchemas::enterpriseUser()->attributes,
            default => CoreSchemas::commonAttributes(),
        };
        [$name, $subName] = explode('.', $path) + [1 => null];
        $attribute = array_find($attributes, static fn (AttributeDefinition $attribute): bool => $attribute->name === $name);
        self::assertNotNull($attribute);
        $definition = null === $subName ? $attribute : $attribute->subAttribute($subName);
        self::assertNotNull($definition);

        return $definition;
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
