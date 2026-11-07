<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\ServiceProvider;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait {
        getKernelParameters as private microKernelParameters;
    }

    /**
     * Without ".kernel.config_dir" a debug build writes no config/reference.php, which php-cs-fixer would reject.
     *
     * @return array<string, mixed>
     */
    protected function getKernelParameters(): array
    {
        $parameters = $this->microKernelParameters();
        unset($parameters['.kernel.config_dir']);

        return $parameters;
    }
}
