<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Mapping;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TacticMedia\ScimServerBundle\Object\Instantiator;
use TacticMedia\ScimServerBundle\Tests\Fixtures\Valid\PasswordUser;
use TacticMedia\ScimServerBundle\Tests\Object\CreatesDocumentMapper;

final class ConstructorResolverTest extends KernelTestCase
{
    use CreatesDocumentMapper;

    #[Test]
    #[TestDox('A writeOnly constructor parameter gets its default, and the write hashes the password')]
    public function writeOnlyParameterIsWrittenAfterConstruction(): void
    {
        $mappings = self::mappings(['User' => PasswordUser::class]);
        $mapping = $mappings->get('User');
        $document = ['userName' => 'bjensen', 'password' => 'Secret1!'];

        $user = new Instantiator(new ServiceLocator([]), self::mapper($mappings))->create($mapping, $document);
        self::mapper($mappings)->write($mapping, $document, $user, true);

        self::assertInstanceOf(PasswordUser::class, $user);
        self::assertTrue(password_verify('Secret1!', (string) $user->password));
    }
}
