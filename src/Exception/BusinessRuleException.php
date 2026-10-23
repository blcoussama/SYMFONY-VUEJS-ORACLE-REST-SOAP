<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Règle métier non respectée par une donnée fournie (ex. technicien inconnu) : 422 côté API.
 */
final class BusinessRuleException extends \DomainException
{
    public function __construct(string $message, public readonly string $field = '')
    {
        parent::__construct($message);
    }
}
