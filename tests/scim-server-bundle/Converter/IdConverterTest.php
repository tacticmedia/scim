<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Converter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServerBundle\Converter\ConversionContext;
use TacticMedia\ScimServerBundle\Converter\ConversionFailed;
use TacticMedia\ScimServerBundle\Converter\IdConverter;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\Device;

final class IdConverterTest extends KernelTestCase
{
    use ConverterCases;
    private const string UUID = '019a5a4e-7c3e-7d4b-9a3f-0c1d2e3f4a5b';

    #[Test]
    #[TestDox('toScim(): UUID in RFC 4122 form, int with the idPrefix, string as is')]
    #[DataProvider('memberIds')]
    public function toScim(ConversionContext $context, mixed $id, ?string $expected): void
    {
        self::assertSame($expected, new IdConverter()->toScim($id, $context));
    }

    /**
     * @return iterable<string, array{ConversionContext, mixed, string|null}>
     */
    public static function memberIds(): iterable
    {
        yield 'Uuid' => [self::context('User', 'id'), Uuid::fromString(self::UUID), self::UUID];
        yield 'int with idPrefix' => [self::context('Device', 'id'), 42, 'd42'];
        yield 'string' => [self::context('Person', 'id'), 'abc', 'abc'];
        yield 'null' => [self::context('Device', 'id'), null, null];
    }

    #[Test]
    #[TestDox('toScim(): an id of another type fails')]
    public function toScimFails(): void
    {
        $this->expectExceptionObject(new \UnexpectedValueException(\sprintf('The id of %s must be a string, an int or a Symfony\Component\Uid\AbstractUid, float given.', Device::class)));

        new IdConverter()->toScim(1.5, self::context('Device', 'id'));
    }

    #[Test]
    #[TestDox('fromScim(): UUID, int after the exact idPrefix, string as is')]
    #[DataProvider('scimIds')]
    public function fromScim(ConversionContext $context, ?string $id, mixed $expected): void
    {
        $converted = new IdConverter()->fromScim($id, $context);

        self::assertEquals($expected, $converted);
        self::assertSame(get_debug_type($expected), get_debug_type($converted));
    }

    /**
     * @return iterable<string, array{ConversionContext, string|null, mixed}>
     */
    public static function scimIds(): iterable
    {
        yield 'Uuid' => [self::context('User', 'id'), self::UUID, Uuid::fromString(self::UUID)];
        yield 'int with idPrefix' => [self::context('Device', 'id'), 'd42', 42];
        yield 'largest int' => [self::context('Device', 'id'), 'd'.\PHP_INT_MAX, \PHP_INT_MAX];
        yield 'string' => [self::context('Person', 'id'), 'abc', 'abc'];
        yield 'null' => [self::context('Device', 'id'), null, null];
    }

    #[Test]
    #[TestDox('fromScim(): a malformed id fails')]
    #[DataProvider('malformedIds')]
    public function fromScimFails(ConversionContext $context, mixed $id, string $message): void
    {
        $this->expectExceptionObject(new ConversionFailed($message));

        new IdConverter()->fromScim($id, $context);
    }

    /**
     * @return iterable<string, array{ConversionContext, mixed, string}>
     */
    public static function malformedIds(): iterable
    {
        $prefixed = 'must be "d" followed by a positive integer.';

        yield 'not a UUID' => [self::context('User', 'id'), 'abc', 'must be a valid Symfony\Component\Uid\Uuid.'];
        yield 'not a string' => [self::context('User', 'id'), 42, 'must be a string.'];
        yield 'idPrefix in another case' => [self::context('Device', 'id'), 'D42', $prefixed];
        yield 'leading zero' => [self::context('Device', 'id'), 'd042', $prefixed];
        yield 'zero' => [self::context('Device', 'id'), 'd0', $prefixed];
        yield 'above PHP_INT_MAX' => [self::context('Device', 'id'), 'd9223372036854775808', $prefixed];
        yield 'negative' => [self::context('IntUser', 'id'), '-1', 'must be a positive integer.'];
    }
}
