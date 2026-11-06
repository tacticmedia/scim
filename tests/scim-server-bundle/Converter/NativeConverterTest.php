<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Converter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServerBundle\Converter\ConversionContext;
use TacticMedia\ScimServerBundle\Converter\ConversionFailed;
use TacticMedia\ScimServerBundle\Converter\NativeConverter;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Enum\DeviceStatus;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Enum\UserType;

final class NativeConverterTest extends KernelTestCase
{
    use ConverterCases;

    #[Test]
    #[TestDox('toScim(): dates in UTC with seconds, enums as their value, other values as they are')]
    #[DataProvider('memberValues')]
    public function toScim(mixed $value, mixed $expected): void
    {
        self::assertSame($expected, new NativeConverter()->toScim($value, self::context('User', 'userName')));
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function memberValues(): iterable
    {
        yield 'string' => ['bjensen', 'bjensen'];
        yield 'int' => [3, 3];
        yield 'bool' => [false, false];
        yield 'null' => [null, null];
        yield 'DateTimeImmutable in another zone' => [new \DateTimeImmutable('2026-11-05T12:00:00.5+02:00'), '2026-11-05T10:00:00Z'];
        yield 'DateTime' => [new \DateTime('2026-11-05T10:00:00Z'), '2026-11-05T10:00:00Z'];
        yield 'string-backed enum' => [UserType::Contractor, 'Contractor'];
    }

    #[Test]
    #[TestDox('fromScim(): scalars, "" for nullable strings, int for float, integral float for int, enum cases')]
    #[DataProvider('scimValues')]
    public function fromScim(ConversionContext $context, mixed $value, mixed $expected): void
    {
        self::assertSame($expected, new NativeConverter()->fromScim($value, $context));
    }

    /**
     * @return iterable<string, array{ConversionContext, mixed, mixed}>
     */
    public static function scimValues(): iterable
    {
        yield 'string' => [self::context('User', 'userName'), 'bjensen', 'bjensen'];
        yield '"" for a nullable string' => [self::context('User', 'displayName'), '', null];
        yield '"" for a string' => [self::context('User', 'userName'), '', ''];
        yield 'bool' => [self::context('User', 'enabled'), false, false];
        yield 'null' => [self::context('User', 'displayName'), null, null];
        yield 'int for a float member' => [self::context('Device', 'weight'), 2, 2.0];
        yield 'integral float for an int member' => [self::context('Device', 'weight', 'int'), -2.0, -2];
        yield 'enum value' => [self::context('User', 'type'), 'Intern', UserType::Intern];
        yield 'enum value in another case' => [self::context('User', 'type'), 'intern', UserType::Intern];
        yield 'exact enum value with caseExact' => [self::context('Device', 'status', caseExact: true), 'active', DeviceStatus::Active];
    }

    /**
     * @param class-string<\DateTimeInterface> $phpType
     * @param class-string<\DateTimeInterface> $expectedClass
     */
    #[Test]
    #[TestDox('fromScim(): a dateTime becomes the member class in UTC, DateTimeImmutable for the interface')]
    #[DataProvider('dateTypes')]
    public function fromScimDate(string $phpType, string $expectedClass): void
    {
        $date = new NativeConverter()->fromScim('2026-11-05T12:00:00+02:00', self::context('User', 'createdAt', $phpType));

        self::assertInstanceOf($expectedClass, $date);
        self::assertSame('2026-11-05T10:00:00+00:00', $date->format(\DATE_ATOM));
    }

    /**
     * @return iterable<string, array{class-string<\DateTimeInterface>, class-string<\DateTimeInterface>}>
     */
    public static function dateTypes(): iterable
    {
        yield 'DateTimeImmutable' => [\DateTimeImmutable::class, \DateTimeImmutable::class];
        yield 'DateTime' => [\DateTime::class, \DateTime::class];
        yield 'DateTimeInterface' => [\DateTimeInterface::class, \DateTimeImmutable::class];
    }

    #[Test]
    #[TestDox('fromScim(): an invalid dateTime, enum value or integer fails')]
    #[DataProvider('invalidValues')]
    public function fromScimFails(ConversionContext $context, mixed $value, string $message): void
    {
        $this->expectExceptionObject(new ConversionFailed($message));

        new NativeConverter()->fromScim($value, $context);
    }

    /**
     * @return iterable<string, array{ConversionContext, mixed, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'dateTime' => [self::context('User', 'createdAt'), 'yesterday', 'must be an xsd:dateTime.'];
        yield 'enum value' => [self::context('User', 'type'), 'Temp', 'must be one of Employee, Contractor, Intern.'];
        yield 'enum value in another case with caseExact' => [self::context('Device', 'status', caseExact: true), 'ACTIVE', 'must be one of active, retired.'];
        yield 'enum value of another type' => [self::context('User', 'type'), 1, 'must be one of Employee, Contractor, Intern.'];
        yield 'fraction for an int member' => [self::context('Device', 'weight', 'int'), 2.5, 'must be an integer.'];
        yield 'float above the int range' => [self::context('Device', 'weight', 'int'), 1e30, 'must be an integer.'];
    }
}
