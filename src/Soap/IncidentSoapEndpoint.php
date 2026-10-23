<?php

declare(strict_types=1);

namespace App\Soap;

use App\Entity\ApiClient;
use Psr\Log\LoggerInterface;

/**
 * Objet exposé à \SoapServer pour UNE requête (document/literal « wrapped ») :
 * chaque opération reçoit l'élément enveloppe (stdClass) et renvoie l'élément réponse.
 *
 * \SoapServer appelle d'abord ApiKey() si l'en-tête SOAP <ApiKey> est présent ;
 * à défaut, la clé de l'en-tête HTTP X-API-Key (passée au constructeur) est utilisée.
 */
final class IncidentSoapEndpoint
{
    private ?string $soapHeaderKey = null;
    private ?ApiClient $client = null;

    public function __construct(
        private readonly IncidentSoapService $service,
        private readonly LoggerInterface $logger,
        private readonly ?string $httpApiKey = null,
    ) {
    }

    /** Gestionnaire de l'en-tête SOAP <tns:ApiKey>. */
    public function ApiKey(mixed $value): void
    {
        $this->soapHeaderKey = \is_scalar($value) ? (string) $value : null;
    }

    /**
     * @return array{incident: array<string, string|bool>}
     */
    public function getIncidentStatus(mixed $parameters): array
    {
        return $this->guard(fn (): array => ['incident' => $this->service->getIncidentStatus($this->client(), self::string($parameters, 'reference'))]);
    }

    /**
     * @return array{reference: string}
     */
    public function createIncident(mixed $parameters): array
    {
        return $this->guard(fn (): array => ['reference' => $this->service->createIncident(
            $this->client(),
            self::string($parameters, 'siteCode'),
            self::string($parameters, 'title'),
            self::string($parameters, 'description'),
            self::string($parameters, 'category'),
            self::string($parameters, 'priority'),
        )]);
    }

    /**
     * @return array{incident: list<array<string, string|bool>>}
     */
    public function listOpenIncidentsBySite(mixed $parameters): array
    {
        return $this->guard(fn (): array => ['incident' => $this->service->listOpenIncidentsBySite($this->client(), self::string($parameters, 'siteCode'))]);
    }

    private function client(): ApiClient
    {
        return $this->client ??= $this->service->authenticate($this->soapHeaderKey ?? $this->httpApiKey);
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function guard(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (SoapServiceException $e) {
            throw self::fault($e->faultCode, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Erreur interne du service SOAP : {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            throw self::fault(SoapServiceException::SERVER, 'Erreur interne du service.');
        }
    }

    private static function fault(string $code, string $message): \SoapFault
    {
        return new \SoapFault($code, $message, null, ['code' => $code, 'message' => $message], 'ServiceFault');
    }

    private static function string(mixed $parameters, string $name): string
    {
        $value = \is_object($parameters) ? ($parameters->{$name} ?? null) : null;

        return \is_scalar($value) ? (string) $value : '';
    }
}
