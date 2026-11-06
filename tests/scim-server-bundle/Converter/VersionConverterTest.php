<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Converter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServerBundle\Converter\ConversionFailed;
use TacticMedia\ScimServerBundle\Converter\VersionConverter;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Entity\User;

final class VersionConverterTest extends KernelTestCase
{
    use ConverterCases;

    #[Test]
    #[TestDox('toScim(): an int becomes a weak entity tag, null stays null')]
    public function toScim(): void
    {
        $context = self::context('User', 'version');

        self::assertSame(['W/"1"', 'W/"42"', null], [new VersionConverter()->toScim(1, $context), new VersionConverter()->toScim(42, $context), new VersionConverter()->toScim(null, $context)]);
    }

    #[Test]
    #[TestDox('toScim(): a value that is not an int fails')]
    public function toScimFails(): void
    {
        $this->expectExceptionObject(new \UnexpectedValueException(\sprintf('The version of %s must be an int, string given.', User::class)));

        new VersionConverter()->toScim('7', self::context('User', 'version'));
    }

    #[Test]
    #[TestDox('fromScim(): a weak or strong entity tag of an integer becomes the int, null stays null')]
    public function fromScim(): void
    {
        $context = self::context('User', 'version');

        self::assertSame([5, 5, 0, \PHP_INT_MAX, null], [
            new VersionConverter()->fromScim('W/"5"', $context),
            new VersionConverter()->fromScim('"5"', $context),
            new VersionConverter()->fromScim('W/"0"', $context),
            new VersionConverter()->fromScim('W/"'.\PHP_INT_MAX.'"', $context),
            new VersionConverter()->fromScim(null, $context),
        ]);
    }

    #[Test]
    #[TestDox('fromScim(): $_dataName fails')]
    #[DataProvider('malformed')]
    public function fromScimFails(mixed $value): void
    {
        $this->expectExceptionObject(new ConversionFailed('must be an entity tag of an integer, for example W/"7".'));

        new VersionConverter()->fromScim($value, self::context('User', 'version'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformed(): iterable
    {
        yield 'a bare number' => ['5'];
        yield 'an int' => [5];
        yield 'an empty string' => [''];
        yield 'a leading zero' => ['W/"05"'];
        yield 'a number above PHP_INT_MAX' => ['W/"9223372036854775808"'];
        yield 'trailing text' => ['W/"5" x'];
    }
}
