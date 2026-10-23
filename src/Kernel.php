<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Toutes les dates sont manipulées et stockées en UTC (affichage : Africa/Casablanca, voir twig.yaml).
        date_default_timezone_set('UTC');

        parent::boot();
    }
}
