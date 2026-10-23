<?php

declare(strict_types=1);

namespace App\Soap;

/**
 * Exécute \SoapServer (mode WSDL, SOAP 1.1) sur le corps d'une requête et renvoie la réponse XML,
 * sans laisser l'extension écrire elle-même dans la sortie ni dans les en-têtes HTTP.
 */
final class IncidentSoapServer
{
    /**
     * @param int $wsdlCache WSDL_CACHE_NONE (0), _DISK (1), _MEMORY (2) ou _BOTH (3)
     */
    public function __construct(private readonly int $wsdlCache = 0)
    {
    }

    public static function isAvailable(): bool
    {
        return \extension_loaded('soap');
    }

    public function handle(string $wsdlPath, string $requestXml, IncidentSoapEndpoint $endpoint): string
    {
        $server = new \SoapServer($wsdlPath, [
            'soap_version' => \SOAP_1_1,
            'encoding' => 'UTF-8',
            'cache_wsdl' => $this->wsdlCache,
            'features' => \SOAP_SINGLE_ELEMENT_ARRAYS,
        ]);
        $server->setObject($endpoint);

        ob_start();
        try {
            $server->handle($requestXml);
        } finally {
            $xml = (string) ob_get_clean();
            if (!headers_sent()) {
                // \SoapServer pose ses propres en-têtes (statut 500, Content-Length) : Symfony les redéfinit.
                header_remove();
            }
        }

        return $xml;
    }
}
