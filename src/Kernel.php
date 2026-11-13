<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Toutes les dates sont calculées et stockées en UTC, quel que soit le php.ini
        // (XAMPP est réglé sur Europe/Berlin ; le PHP des crons cPanel peut différer du PHP web).
        // L'affichage à l'heure de Yaoundé/Douala (UTC+1) est fait par le front.
        date_default_timezone_set('UTC');

        parent::boot();
    }
}
