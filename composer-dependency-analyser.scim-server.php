<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

return new Configuration()
    // psr/http-message, psr/http-server-handler and symfony/psr-http-message-bridge are suggested, not required: only the PSR-15 handler uses them.
    ->ignoreUnknownClassesRegex('~^(Psr\\\\Http\\\\|Symfony\\\\Bridge\\\\PsrHttpMessage\\\\)~');
