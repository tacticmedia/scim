<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use TacticMedia\ScimServerBundle\TacticMediaScimServerBundle;

return [
    FrameworkBundle::class => ['all' => true],
    DoctrineBundle::class => ['all' => true],
    SecurityBundle::class => ['all' => true],
    TwigBundle::class => ['all' => true],
    TacticMediaScimServerBundle::class => ['all' => true],
];
