<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\ApiKeyAuthenticator;
use App\Soap\IncidentSoapEndpoint;
use App\Soap\IncidentSoapServer;
use App\Soap\IncidentSoapService;
use App\Soap\SoapRequestValidator;
use App\Soap\WsdlProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Point d'accès SOAP (SPEC §6) : GET ?wsdl sert le contrat, POST exécute une opération.
 * Le pare-feu est désactivé sur /soap/ : l'authentification par clé est faite par le service
 * et les erreurs sont des fautes SOAP (HTTP 500, comme l'exige SOAP 1.1).
 */
final class SoapController
{
    public function __construct(
        private readonly WsdlProvider $wsdl,
        private readonly IncidentSoapServer $server,
        private readonly IncidentSoapService $service,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly SoapRequestValidator $validator,
    ) {
    }

    #[Route('/soap/incidents', name: 'soap_incidents', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $location = $this->urlGenerator->generate('soap_incidents', [], UrlGeneratorInterface::ABSOLUTE_URL);

        if ($request->isMethod('GET')) {
            if ($request->query->has('wsdl') || $request->query->has('WSDL')) {
                return new Response($this->wsdl->contents($location), 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
            }

            return new Response(
                "Service SOAP NetOps (incidents, v1).\nContrat WSDL : {$location}?wsdl\n",
                200,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        if (!IncidentSoapServer::isAvailable()) {
            return new Response('L’extension PHP soap n’est pas installée sur ce serveur.', 503, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        if (null !== $invalid = $this->validator->validate($request->getContent())) {
            return new Response(SoapRequestValidator::faultXml($invalid['code'], $invalid['message']), 500, ['Content-Type' => 'text/xml; charset=UTF-8']);
        }

        $endpoint = new IncidentSoapEndpoint($this->service, $this->logger, $request->headers->get(ApiKeyAuthenticator::HEADER));
        $xml = $this->server->handle($this->wsdl->path($location), $request->getContent(), $endpoint);
        $isFault = 1 === preg_match('/<(?:[A-Za-z0-9_-]+:)?Fault>/', $xml);

        return new Response($xml, $isFault ? 500 : 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }
}
