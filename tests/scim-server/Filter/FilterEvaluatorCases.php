<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Filter;

use TacticMedia\ScimServer\SchemaUrn;

/**
 * Filters and whether they match {@see self::DOCUMENT}, or the document of the row.
 */
final class FilterEvaluatorCases
{
    private const string TEST = 'urn:example:params:scim:schemas:extension:test:2.0:User';
    public const array DOCUMENT = [
        'schemas' => [SchemaUrn::User->value, SchemaUrn::EnterpriseUser->value, self::TEST],
        'id' => '2819c223-7f76-453a-919d-413861904646',
        'userName' => 'bjensen',
        'name' => ['formatted' => 'Ms. Barbara J Jensen III', 'familyName' => 'Jensen', 'givenName' => 'Barbara', 'middleName' => ''],
        'nickName' => null,
        'title' => 'Tour Guide',
        'userType' => 'Employee',
        'active' => true,
        'emails' => [
            ['value' => 'bjensen@example.com', 'type' => 'work', 'primary' => true],
            ['value' => 'babs@jensen.org', 'type' => 'home'],
        ],
        'ims' => [],
        'photos' => [['value' => 'https://photos.example.com/profilephoto/72930000000Ccne/F', 'type' => 'photo']],
        'addresses' => [['streetAddress' => '100 Universal City Plaza', 'type' => 'work'], ['streetAddress' => null]],
        'x509Certificates' => [['value' => 'MIIDQzCC']],
        'meta' => ['resourceType' => 'User', 'created' => '2010-01-23T04:56:22Z', 'lastModified' => '2011-05-13T04:42:34Z'],
        SchemaUrn::EnterpriseUser->value => [
            'employeeNumber' => '701984',
            'manager' => ['value' => '26118915-6090-4610-87e4-49d8ca9f808d', 'displayName' => 'John Smith'],
        ],
        self::TEST => [
            'loginCount' => 10,
            'score' => 4.5,
            'avatar' => 'YQ==',
            'homepage' => 'https://Example.com/bjensen',
            'tags' => ['alpha', 'beta'],
            'birthday' => '1980-02-03T00:00:00+01:00',
        ],
    ];

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function strings(): iterable
    {
        yield 'eq ignores case' => ['userName eq "BJENSEN"', true];
        yield 'eq on another value' => ['userName eq "jsmith"', false];
        yield 'eq keeps case when caseExact' => ['id eq "2819C223-7F76-453A-919D-413861904646"', false];
        yield 'eq when caseExact' => ['id eq "2819c223-7f76-453a-919d-413861904646"', true];
        yield 'ne on another value' => ['userName ne "jsmith"', true];
        yield 'ne on the value' => ['userName ne "BJensen"', false];
        yield 'ne on an unassigned attribute' => ['nickName ne "x"', true];
        yield 'ne when another of many values is not equal' => ['emails.type ne "work"', true];
        yield 'ne when every value is equal' => ['photos.type ne "photo"', false];
        yield 'co' => ['name.familyName co "ENS"', true];
        yield 'co without the substring' => ['name.familyName co "smith"', false];
        yield 'co on any email value' => ['emails co "EXAMPLE.COM"', true];
        yield 'ew on no email value' => ['emails ew ".net"', false];
        yield 'co on caseExact reference' => ['photos co "72930000000ccne"', false];
        yield 'co on caseExact reference in its case' => ['photos co "72930000000Ccne"', true];
        yield 'eq on multi-valued string' => ['tags eq "BETA"', true];
        yield 'eq on unassigned attribute' => ['nickName eq "x"', false];
        yield 'eq on empty multi-valued attribute' => ['ims.type eq "xmpp"', false];
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function orderings(): iterable
    {
        yield 'string gt' => ['userName gt "A"', true];
        yield 'string gt on equal value' => ['userName gt "BJENSEN"', false];
        yield 'string ge on equal value' => ['userName ge "BJENSEN"', true];
        yield 'string lt' => ['userName lt "a"', false];
        yield 'string le on equal value' => ['userName le "bjensen"', true];
        yield 'dateTime ge on same instant in other zone' => ['meta.lastModified ge "2011-05-13T14:42:34+10:00"', true];
        yield 'dateTime eq in other zone' => ['meta.lastModified eq "2011-05-13T14:42:34+10:00"', true];
        yield 'dateTime with offset in document' => ['birthday lt "1980-02-03T00:00:00Z"', true];
        yield 'integer le' => ['loginCount le 9', false];
        yield 'decimal lt integer' => ['score lt 5', true];
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function otherTypes(): iterable
    {
        yield 'boolean eq Entra string' => ['active eq "TRUE"', true];
        yield 'boolean eq other Entra string' => ['active eq "False"', false];
        yield 'binary eq' => ['x509Certificates eq "MIIDQzCC"', true];
        yield 'binary eq in other case' => ['x509Certificates eq "miidqzcc"', false];
        yield 'extension string' => ['employeeNumber eq "701984"', true];
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function presence(): iterable
    {
        yield 'pr' => ['title pr', true];
        yield 'pr on null' => ['nickName pr', false];
        yield 'pr on empty string' => ['name.middleName pr', false];
        yield 'pr on singular complex' => ['name pr', true];
        yield 'pr on multi-valued complex without value' => ['addresses pr', true];
        yield 'eq null on null' => ['nickName eq null', true];
        yield 'eq null on a value' => ['title eq null', false];
        yield 'ne null on a value' => ['title ne null', true];
        yield 'ne null on null' => ['nickName ne null', false];
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function logic(): iterable
    {
        yield 'and' => ['title pr and userType eq "Employee"', true];
        yield 'and with one false side' => ['title pr and userType eq "Intern"', false];
        yield 'or with one true side' => ['nickName pr or userType eq "Employee"', true];
        yield 'or with two false sides' => ['nickName pr or userType eq "Intern"', false];
        yield 'not false' => ['not (userType eq "Intern")', true];
        yield 'not true' => ['not (title pr)', false];
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function valuePaths(): iterable
    {
        yield 'one email satisfies both' => ['emails[type eq "work" and value co "@example.com"]', true];
        yield 'no single email satisfies both' => ['emails[type eq "home" and value co "example.com"]', false];
        yield 'attribute expressions may use different emails' => ['emails.type eq "home" and emails.value co "example.com"', true];
        yield 'not inside the value path' => ['emails[not (type eq "work")]', true];
        yield 'empty multi-valued attribute' => ['ims[type eq "xmpp"]', false];
        yield 'singular complex' => ['name[givenName eq "Barbara"]', true];
        yield 'no element has the sub-attribute' => ['addresses[locality pr]', false];
        yield 'Entra value path' => ['emails[type eq "work"].value eq "bjensen@example.com"', true];
        yield 'Entra value path on another email' => ['emails[type eq "home"].value eq "bjensen@example.com"', false];
    }

    /**
     * @return iterable<string, array{string, bool, array<string, mixed>}>
     */
    public static function documentsWithValuesThatDoNotFit(): iterable
    {
        $document = [
            'active' => 'maybe',
            'emails' => ['not an object'],
            'meta' => ['lastModified' => 'yesterday'],
            SchemaUrn::EnterpriseUser->value => 'not an object',
            self::TEST => ['loginCount' => 'ten', 'tags' => 'alpha'],
        ];

        yield 'integer gt on a string' => ['loginCount gt 1', false, $document];
        yield 'integer ne on a string' => ['loginCount ne 1', true, $document];
        yield 'boolean eq on another string' => ['active eq true', false, $document];
        yield 'extension that is not an object' => ['employeeNumber pr', false, $document];
        yield 'multi-valued attribute that is not a list' => ['tags pr', false, $document];
        yield 'sub-attribute of an element that is not an object' => ['emails.value pr', false, $document];
        yield 'value path on an element that is not an object' => ['emails[type pr]', false, $document];
    }
}
