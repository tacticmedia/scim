<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Converter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use TacticMedia\ScimServerBundle\Converter\ConversionContext;
use TacticMedia\ScimServerBundle\Converter\ConversionFailed;
use TacticMedia\ScimServerBundle\Converter\HashPassword;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Tests\Converter\Fixtures\RejectingHasher;
use TacticMedia\ScimServerBundle\Tests\Object\Fixtures\Account;

final class HashPasswordTest extends KernelTestCase
{
    #[Test]
    #[TestDox('fromScim() hashes the password with the hasher of the object class')]
    public function passwordIsHashed(): void
    {
        $hash = $this->converter()->fromScim('secret', $this->context(new Account('jdoe')));

        self::assertIsString($hash);
        self::assertTrue(password_verify('secret', $hash));
    }

    #[Test]
    #[TestDox('fromScim() keeps null')]
    public function nullStaysNull(): void
    {
        self::assertNull($this->converter()->fromScim(null, $this->context(new Account('jdoe'))));
    }

    #[Test]
    #[TestDox('fromScim() fails for a value that is not a string or an object that is not a PasswordAuthenticatedUserInterface')]
    #[DataProvider('invalidCases')]
    public function invalidCaseFails(mixed $value, ?object $object): void
    {
        $this->expectExceptionObject(new ConversionFailed(\sprintf('must be a string, and HashPassword needs an object that implements %s.', PasswordAuthenticatedUserInterface::class)));

        $this->converter()->fromScim($value, $this->context($object));
    }

    /**
     * @return iterable<string, array{mixed, object|null}>
     */
    public static function invalidCases(): iterable
    {
        yield 'not a string' => [42, new Account('jdoe')];
        yield 'no object' => ['secret', null];
        yield 'not a password user' => ['secret', new \stdClass()];
    }

    #[Test]
    #[TestDox('fromScim() fails for a password that is too long or that the hasher rejects')]
    public function rejectedPasswordFails(): void
    {
        $rejecting = new HashPassword(new UserPasswordHasher(new PasswordHasherFactory([PasswordAuthenticatedUserInterface::class => ['instance' => new RejectingHasher()]])));
        $failures = [];

        foreach ([[$this->converter(), str_repeat('a', 4097)], [$rejecting, 'secret']] as [$converter, $password]) {
            try {
                $converter->fromScim($password, $this->context(new Account('jdoe')));
            } catch (ConversionFailed $conversionFailed) {
                $failures[] = $conversionFailed->getMessage();
            }
        }

        self::assertSame(['must be at most 4096 bytes.', 'Too simple.'], $failures);
    }

    #[Test]
    #[TestDox('toScim() fails, because the password is never read')]
    public function passwordIsNeverRead(): void
    {
        $this->expectExceptionObject(new \LogicException('The password is writeOnly and is never read.'));

        $this->converter()->toScim('hash', $this->context(new Account('jdoe')));
    }

    private function converter(): HashPassword
    {
        return new HashPassword(new UserPasswordHasher(new PasswordHasherFactory([PasswordAuthenticatedUserInterface::class => ['algorithm' => 'bcrypt', 'cost' => 4]])));
    }

    private function context(?object $object): ConversionContext
    {
        $resource = new AttributeLoader()->load(Account::class);

        return new ConversionContext($resource, $resource->attributes[1], $object);
    }
}
