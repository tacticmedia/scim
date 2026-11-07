<?php

declare(strict_types=1);

/*
 * The demo SP on the root autoloader. demo/service-provider/vendor/ holds the released packages, not the working copy.
 */

use Symfony\Component\HttpFoundation\Request;
use TacticMedia\ScimDemo\ServiceProvider\Kernel;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$kernel = new Kernel('test', true);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
