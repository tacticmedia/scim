<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

return new Configuration()
    // config/ ships too: routes.php and services.php use the routing and DI components. bin/dependencies installs here.
    ->addPathToScan(__DIR__.'/var/dependencies/scim-server-bundle/config', isDev: false)
    // service(), param() and the other configurator functions live in ContainerConfigurator.php, not in an autoload file.
    ->ignoreUnknownFunctionsRegex('~^Symfony\\\\Component\\\\DependencyInjection\\\\Loader\\\\Configurator\\\\~')
    // doctrine/orm and doctrine/doctrine-bundle are suggested, not required: the ORM store loads only when they exist.
    ->ignoreUnknownClassesRegex('~^Doctrine\\\\~')
    // symfony/validator is suggested: ObjectValidator gets null without it. UniqueEntity is only an instanceof check.
    ->ignoreUnknownClassesRegex('~^Symfony\\\\(Component\\\\Validator|Bridge\\\\Doctrine)\\\\~');
