<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$applicationDir = __DIR__.'/var/dependencies/service-provider';

return new Configuration()
    // config/, public/ and bin/console ship too. bin/dependencies installs here.
    ->addPathsToScan([$applicationDir.'/config', $applicationDir.'/public', $applicationDir.'/bin/console'], isDev: false)
    // No class name loads them: bin/console runs on symfony/console, the runtime loads .env, the DBAL driver is pdo_sqlite.
    ->ignoreErrorsOnPackages(['symfony/console', 'symfony/dotenv', 'symfony/runtime'], [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnExtension('ext-pdo_sqlite', [ErrorType::UNUSED_DEPENDENCY]);
