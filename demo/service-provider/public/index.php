<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;
use TacticMedia\ScimDemo\ServiceProvider\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$environment = $_SERVER['APP_ENV'] ?? 'dev';
$kernel = new Kernel($environment, 'prod' !== $environment);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
