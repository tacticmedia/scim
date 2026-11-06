<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Validation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Mapping\ResourceMapping;
use TacticMedia\ScimServerBundle\Tests\Fixtures\CreatesSchema;
use TacticMedia\ScimServerBundle\Tests\Validation\Fixtures\Member;
use TacticMedia\ScimServerBundle\Tests\Validation\Fixtures\Visitor;
use TacticMedia\ScimServerBundle\Validation\ObjectValidator;

/**
 * The object validator of the test kernel, which has symfony/validator.
 */
final class ObjectValidatorTest extends KernelTestCase
{
    use CreatesSchema;

    #[Test]
    #[TestDox('A mapping without validationGroups validates nothing')]
    public function withoutGroupsValidatesNothing(): void
    {
        $this->validator()->validate(new AttributeLoader()->load(Visitor::class), new Visitor());

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    #[TestDox('Violations of the validationGroups are 400 invalidValue with the SCIM path of each member; Default is not validated')]
    public function violationsAreInvalidValue(): void
    {
        self::createSchema();
        $root = new Member('root');
        $root->email = 'not an email';

        $exception = $this->failure(new AttributeLoader()->load(Member::class), $root);

        self::assertSame(
            [400, ScimErrorType::InvalidValue, 'The user name root is reserved. Attribute "emails[type eq "work"].value": This value is not a valid email address.'],
            [$exception->status, $exception->scimType, $exception->detail],
        );
    }

    #[Test]
    #[TestDox('A UniqueEntity violation is 409 uniqueness')]
    public function uniqueEntityIsUniqueness(): void
    {
        $entityManager = self::createSchema();
        $alice = new Member('alice');
        $alice->email = 'team@example.com';

        $entityManager->persist($alice);
        $entityManager->flush();

        $bob = new Member('bob');
        $bob->email = 'team@example.com';

        $exception = $this->failure(new AttributeLoader()->load(Member::class), $bob);

        self::assertSame([409, ScimErrorType::Uniqueness, 'Attribute "emails[type eq "work"].value": This value is already used.'], [$exception->status, $exception->scimType, $exception->detail]);
    }

    #[Test]
    #[TestDox('validationGroups without the validator service is a LogicException')]
    public function withoutValidatorFails(): void
    {
        $this->expectExceptionObject(new \LogicException(Member::class.': validationGroups: needs the validator service. Install symfony/validator and enable framework.validation.'));

        new ObjectValidator()->validate(new AttributeLoader()->load(Member::class), new Member('alice'));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel(['environment' => 'validation'] + $options);
    }

    private function validator(): ObjectValidator
    {
        return self::getContainer()->get('test.tacticmedia_scim_server.object_validator');
    }

    private function failure(ResourceMapping $mapping, object $object): ScimException
    {
        try {
            $this->validator()->validate($mapping, $object);
        } catch (ScimException $scimException) {
            return $scimException;
        }

        self::fail('No ScimException.');
    }
}
