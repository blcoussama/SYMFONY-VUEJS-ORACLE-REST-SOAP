<?php

declare(strict_types=1);

namespace App\Service\Sla;

/**
 * Durée lisible en français : « 2 j 3 h », « 3 h 20 min », « 12 min », « moins d’une minute ».
 */
final class DurationFormatter
{
    public static function format(int $seconds): string
    {
        $seconds = abs($seconds);
        if ($seconds < 60) {
            return 'moins d’une minute';
        }

        $minutes = intdiv($seconds, 60);
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $mins = $minutes % 60;

        if ($days > 0) {
            return $hours > 0 ? \sprintf('%d j %d h', $days, $hours) : \sprintf('%d j', $days);
        }
        if ($hours > 0) {
            return $mins > 0 ? \sprintf('%d h %02d min', $hours, $mins) : \sprintf('%d h', $hours);
        }

        return \sprintf('%d min', $mins);
    }

    public static function formatMinutes(?float $minutes): string
    {
        return null === $minutes ? '—' : self::format((int) round($minutes * 60));
    }
}
