#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Client SOAP d'exemple pour NetOps (ext-soap requise).
 *
 *   php bin/soap-client-demo.php [URL du WSDL] [clé d'API]
 *
 * Par défaut : http://127.0.0.1:8000/soap/incidents?wsdl et la clé de démonstration FICTIVE
 * des fixtures (variable NETOPS_API_KEY pour en passer une autre). Le script crée un incident,
 * relit son statut, liste les incidents ouverts du site puis provoque une faute Client.NotFound.
 */

if (!extension_loaded('soap')) {
    fwrite(\STDERR, "L'extension PHP soap est requise (ex. docker-php-ext-install soap).\n");
    exit(1);
}

$wsdl = $argv[1] ?? (getenv('NETOPS_WSDL') ?: 'http://127.0.0.1:8000/soap/incidents?wsdl');
$apiKey = $argv[2] ?? (getenv('NETOPS_API_KEY') ?: 'nop_demo_cle_factice_pour_tests_locaux_0001');
const NS = 'urn:netops:incidents:v1';

$client = new SoapClient($wsdl, [
    'cache_wsdl' => \WSDL_CACHE_NONE,
    'exceptions' => true,
    'trace' => true,
    'features' => \SOAP_SINGLE_ELEMENT_ARRAYS,
    'connection_timeout' => 10,
]);
// Authentification par en-tête SOAP <ApiKey> (l'en-tête HTTP X-API-Key est aussi accepté).
$client->__setSoapHeaders([new SoapHeader(NS, 'ApiKey', $apiKey)]);

$print = static function (string $title, mixed $value): void {
    echo "== {$title}\n", json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), "\n\n";
};

try {
    echo 'Opérations : ', implode(' | ', $client->__getFunctions() ?? []), "\n\n";

    $created = $client->createIncident([
        'siteCode' => 'TNG-0042',
        'title' => 'Démo SOAP : perte de synchronisation radio',
        'description' => 'Incident créé par bin/soap-client-demo.php (données fictives).',
        'category' => 'RADIO',
        'priority' => 'P2',
    ]);
    $print('createIncident', $created);

    $print('getIncidentStatus', $client->getIncidentStatus(['reference' => $created->reference]));
    $print('listOpenIncidentsBySite TNG-0042', $client->listOpenIncidentsBySite(['siteCode' => 'TNG-0042']));

    try {
        $client->getIncidentStatus(['reference' => 'INC-2000-999999']);
    } catch (SoapFault $fault) {
        echo "== Faute attendue\n{$fault->faultcode} : {$fault->getMessage()}\n";
    }
} catch (SoapFault $fault) {
    fwrite(\STDERR, "Faute SOAP {$fault->faultcode} : {$fault->getMessage()}\n");
    exit(2);
}
