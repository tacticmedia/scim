<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Tests\Patch;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use TacticMedia\ScimServer\Patch\PatchOperation;
use TacticMedia\ScimServer\Patch\PatchOperationType;
use TacticMedia\ScimServer\Patch\PatchRedactor;
use TacticMedia\ScimServer\Patch\PatchRequest;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Returned;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Schema\SchemaExtension;

final class PatchRedactorTest extends KernelTestCase
{
    private const string ACCOUNT = 'urn:example:scim:schemas:core:2.0:Account';
    private const string TOKEN = 'urn:example:scim:schemas:extension:2.0:Token';

    /**
     * @return iterable<string, array{PatchOperation, mixed}>
     */
    public static function operations(): iterable
    {
        yield 'a path to a writeOnly attribute' => [new PatchOperation(PatchOperationType::Replace, 'PASSWORD', 's3cret'), null];
        yield 'a path to a sub-attribute that is never returned' => [new PatchOperation(PatchOperationType::Replace, 'keys[value eq "k1"].secret', 's3cret'), null];
        yield 'a path to a sub-attribute of a writeOnly attribute' => [new PatchOperation(PatchOperationType::Replace, 'vault.code', 's3cret'), null];
        yield 'a path to a returned attribute' => [new PatchOperation(PatchOperationType::Replace, 'userName', 'bjensen'), 'bjensen'];
        yield 'a path-less value' => [new PatchOperation(PatchOperationType::Replace, null, ['userName' => 'bjensen', 'password' => 's3cret', 'vault.code' => 's3cret', 'unknown' => 'kept']), ['userName' => 'bjensen', 'unknown' => 'kept']];
        yield 'URN-qualified keys' => [new PatchOperation(PatchOperationType::Add, null, [self::TOKEN.':token' => 's3cret', self::TOKEN.':label' => 'ci']), [self::TOKEN.':label' => 'ci']];
        yield 'an extension key' => [new PatchOperation(PatchOperationType::Add, null, [self::TOKEN => ['token' => 's3cret', 'label' => 'ci']]), [self::TOKEN => ['label' => 'ci']]];
        yield 'an extension path' => [new PatchOperation(PatchOperationType::Add, self::TOKEN, ['token' => 's3cret', 'label' => 'ci']), ['label' => 'ci']];
        yield 'a list of complex values' => [new PatchOperation(PatchOperationType::Add, 'keys', [['value' => 'k1', 'secret' => 's3cret']]), [['value' => 'k1']]];
        yield 'a complex value of a value filter' => [new PatchOperation(PatchOperationType::Replace, 'keys[value eq "k1"]', ['value' => 'k1', 'secret' => 's3cret']), ['value' => 'k1']];
        yield 'a value that is not an object' => [new PatchOperation(PatchOperationType::Add, null, 'text'), 'text'];
    }

    #[Test]
    #[DataProvider('operations')]
    #[TestDox('Redacts $_dataName')]
    public function redacts(PatchOperation $operation, mixed $value): void
    {
        $redacted = new PatchRedactor()->redact(new PatchRequest([$operation]), $this->accountType());

        self::assertEquals(new PatchRequest([new PatchOperation($operation->type, $operation->path, $value)]), $redacted);
    }

    private function accountType(): ResourceType
    {
        return new ResourceType('Account', '/Accounts', new Schema(self::ACCOUNT, 'Account', 'Account', [
            new AttributeDefinition('userName'),
            new AttributeDefinition('password', mutability: Mutability::WriteOnly),
            new AttributeDefinition('keys', AttributeType::Complex, true, subAttributes: [
                new AttributeDefinition('value'),
                new AttributeDefinition('secret', returned: Returned::Never),
            ]),
            new AttributeDefinition('vault', AttributeType::Complex, mutability: Mutability::WriteOnly, subAttributes: [new AttributeDefinition('code')]),
        ]), [new SchemaExtension(new Schema(self::TOKEN, 'Token', 'Token', [
            new AttributeDefinition('token', mutability: Mutability::WriteOnly),
            new AttributeDefinition('label'),
        ]))]);
    }
}
