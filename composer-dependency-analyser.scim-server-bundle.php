<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return new Configuration()
    // Delete when scim-server-bundle/src/ first uses tacticmedia/scim-server.
    ->ignoreErrorsOnPackage('tacticmedia/scim-server', [ErrorType::UNUSED_DEPENDENCY])
    // AbstractBundle extends classes of these packages, and symfony/http-kernel does not require them.
    ->ignoreErrorsOnPackages(['symfony/config', 'symfony/dependency-injection'], [ErrorType::UNUSED_DEPENDENCY]);
