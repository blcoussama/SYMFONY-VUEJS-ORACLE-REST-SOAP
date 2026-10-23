<?php

declare(strict_types=1);

namespace App\Soap;

/**
 * Erreur métier du service SOAP, convertie en faute SOAP par IncidentSoapEndpoint
 * (cette classe ne dépend pas de ext-soap, ce qui permet de tester le service sans l'extension).
 */
final class SoapServiceException extends \RuntimeException
{
    public const UNAUTHORIZED = 'Client.Unauthorized';
    public const NOT_FOUND = 'Client.NotFound';
    public const VALIDATION = 'Client.Validation';
    public const SERVER = 'Server';

    public function __construct(public readonly string $faultCode, string $message)
    {
        parent::__construct($message);
    }
}
