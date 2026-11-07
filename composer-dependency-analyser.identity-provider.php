<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$applicationDir = __DIR__.'/var/dependencies/identity-provider';

return new Configuration()
    // config/, public/ and bin/console ship too. bin/dependencies installs here.
    ->addPathsToScan([$applicationDir.'/config', $applicationDir.'/public', $applicationDir.'/bin/console'], isDev: false)
    // No class name loads them: the runtime loads .env, the DBAL driver is pdo_sqlite, and framework configuration
    // enables the clock, csrf_protection and http_client services.
    ->ignoreErrorsOnPackages(['symfony/clock', 'symfony/dotenv', 'symfony/http-client', 'symfony/runtime', 'symfony/security-csrf'], [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnExtension('ext-pdo_sqlite', [ErrorType::UNUSED_DEPENDENCY])
    // The root test suite provides it to the "test" environment.
    ->ignoreUnknownClasses([TacticMedia\ScimDemo\Tests\IdentityProvider\FakeServiceProvider::class]);
