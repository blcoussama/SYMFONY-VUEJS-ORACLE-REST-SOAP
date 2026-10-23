<?php

declare(strict_types=1);

namespace App\Enum;

/** Canal par lequel l'incident a été déclaré. */
enum IncidentSource: string
{
    case Web = 'WEB';
    case Rest = 'API_REST';
    case Soap = 'API_SOAP';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Application web',
            self::Rest => 'API REST',
            self::Soap => 'API SOAP',
        };
    }
}
