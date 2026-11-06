<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Validation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use TacticMedia\ScimServer\Exception\ScimErrorType;
use TacticMedia\ScimServerBundle\Mapping\AttributeLoader;
use TacticMedia\ScimServerBundle\Tests\Validation\Fixtures\Member;
use TacticMedia\ScimServerBundle\Tests\Validation\Fixtures\Roster;
use TacticMedia\ScimServerBundle\Validation\ViolationMapper;

final class ViolationMapperTest extends KernelTestCase
{
    #[Test]
    #[TestDox('Each violation names the declared path of its member; a class or unmapped violation names none')]
    public function namesDeclaredPaths(): void
    {
        $exception = ViolationMapper::exception(new AttributeLoader()->load(Member::class), new ConstraintViolationList([
            $this->violation('userName', 'Too short.'),
            $this->violation('email', 'Not an email.', new Email()),
            $this->violation('', 'Reserved.'),
            $this->violation('loginCount', 'Negative.'),
        ]));

        self::assertSame(
            [400, ScimErrorType::InvalidValue, 'Attribute "userName": Too short. Attribute "emails[type eq "work"].value": Not an email. Reserved. Negative.'],
            [$exception->status, $exception->scimType, $exception->detail],
        );
    }

    #[Test]
    #[TestDox('A nested property path names its top-level member')]
    public function nestedPathNamesTopLevelMember(): void
    {
        $exception = ViolationMapper::exception(new AttributeLoader()->load(Roster::class), new ConstraintViolationList([$this->violation('members[1].userName', 'Too short.')]));

        self::assertSame('Attribute "members[type eq "User"]": Too short.', $exception->detail);
    }

    #[Test]
    #[TestDox('A UniqueEntity violation is 409 uniqueness and names only the values in use')]
    public function uniqueEntityIsUniqueness(): void
    {
        $exception = ViolationMapper::exception(new AttributeLoader()->load(Member::class), new ConstraintViolationList([
            $this->violation('userName', 'Too short.'),
            $this->violation('email', 'This value is already used.', new UniqueEntity(fields: ['email'])),
        ]));

        self::assertSame([409, ScimErrorType::Uniqueness, 'Attribute "emails[type eq "work"].value": This value is already used.'], [$exception->status, $exception->scimType, $exception->detail]);
    }

    private function violation(string $propertyPath, string $message, ?Constraint $constraint = null): ConstraintViolation
    {
        return new ConstraintViolation($message, null, [], null, $propertyPath, null, constraint: $constraint);
    }
}
