<?php

declare(strict_types=1);

namespace App\Exception;

/** Verrou optimiste : l'incident a été modifié depuis sa lecture par le client. */
final class IncidentConflictException extends \RuntimeException
{
    public function __construct(string $message = 'L’incident a été modifié entre-temps : rechargez-le avant de recommencer.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
