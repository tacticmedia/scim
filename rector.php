<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\ClassLike\NewlineBetweenClassLikeStmtsRector;
use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;

// conf/bleedingEdge.neon includes phar://phpstan.phar/, an alias only the phpstan binary registers.
Phar::loadPhar(__DIR__.'/vendor/phpstan/phpstan/phpstan.phar', 'phpstan.phar');

return RectorConfig::configure()
    ->withParallel()
    ->withMemoryLimit('-1')
    ->withCache(
        cacheDirectory: __DIR__.'/var/cache/rector',
        cacheClass: Rector\Caching\ValueObject\Storage\FileCacheStorage::class,
        containerCacheDirectory: __DIR__.'/var/cache/test',
    )
    ->withSymfonyContainerPhp(__DIR__.'/var/cache/test/TacticMedia_ScimServerBundle_Tests_Fixtures_TestKernelTestDebugContainer.php')
    ->withPHPStanConfigs([
        __DIR__.'/phpstan.dist.neon',
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        typeDeclarationDocblocks: true,
        privatization: true,
    )
    ->withAttributesSets(all: true)
    ->withPhpSets(php85: true)
    ->withPaths([
        __DIR__.'/scim-server/src',
        __DIR__.'/scim-server-bundle/src',
        __DIR__.'/demo/identity-provider/src',
        __DIR__.'/demo/service-provider/src',
        __DIR__.'/tests',
    ])
    ->withRootFiles()
    ->withComposerBased(
        phpunit: true,
        symfony: true,
    )
    ->withSkip([
        NewlineBetweenClassLikeStmtsRector::class, // php-cs-fixer strips the blank lines again; skipping keeps rector --dry-run clean
        ClassPropertyAssignToConstructorPromotionRector::class, // I don't always want to use constructor promotion
    ])
;
