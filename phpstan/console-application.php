<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Console\Application;
use TacticMedia\ScimServerBundle\Tests\Fixtures\TestKernel;

/**
 * @see https://github.com/phpstan/phpstan-symfony
 */
require __DIR__.'/../vendor/autoload.php';

return new Application(new TestKernel('test', true));
