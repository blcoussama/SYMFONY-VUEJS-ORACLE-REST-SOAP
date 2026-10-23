<?php

declare(strict_types=1);

namespace App\Soap;

/**
 * Contrôle de l'enveloppe AVANT \SoapServer : sur un XML invalide ou une opération inconnue,
 * ext-soap interrompt le processus PHP (« Bad Request », erreur fatale) au lieu de lever une
 * exception. On répond donc nous-mêmes par une faute SOAP 1.1 propre.
 */
final class SoapRequestValidator
{
    public const ENVELOPE_NS = 'http://schemas.xmlsoap.org/soap/envelope/';
    public const SERVICE_NS = 'urn:netops:incidents:v1';
    public const OPERATIONS = ['getIncidentStatus', 'createIncident', 'listOpenIncidentsBySite'];
    private const KNOWN_HEADERS = ['ApiKey'];

    /**
     * @return array{code: string, message: string}|null null si l'enveloppe peut être confiée à \SoapServer
     */
    public function validate(string $xml): ?array
    {
        if ('' === trim($xml)) {
            return ['code' => 'Client', 'message' => 'Requête SOAP vide.'];
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadXML($xml, \LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || null === $dom->documentElement) {
            return ['code' => 'Client', 'message' => 'Requête SOAP invalide : XML mal formé.'];
        }
        if (null !== $dom->doctype) {
            return ['code' => 'Client', 'message' => 'Les DTD ne sont pas acceptées.'];
        }

        $envelope = $dom->documentElement;
        if ('Envelope' !== $envelope->localName || self::ENVELOPE_NS !== $envelope->namespaceURI) {
            return ['code' => 'VersionMismatch', 'message' => 'Enveloppe SOAP 1.1 attendue.'];
        }

        $body = null;
        foreach ($envelope->childNodes as $child) {
            if (!$child instanceof \DOMElement || self::ENVELOPE_NS !== $child->namespaceURI) {
                continue;
            }
            if ('Header' === $child->localName && null !== $fault = $this->checkHeaders($child)) {
                return $fault;
            }
            if ('Body' === $child->localName) {
                $body = $child;
            }
        }
        if (null === $body) {
            return ['code' => 'Client', 'message' => 'Élément Body absent.'];
        }

        foreach ($body->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                return self::SERVICE_NS === $child->namespaceURI && \in_array($child->localName, self::OPERATIONS, true)
                    ? null
                    : ['code' => 'Client', 'message' => \sprintf('Opération inconnue : « %s ». Opérations : %s.', $child->localName, implode(', ', self::OPERATIONS))];
            }
        }

        return ['code' => 'Client', 'message' => 'Aucune opération dans le Body.'];
    }

    /**
     * @return array{code: string, message: string}|null
     */
    private function checkHeaders(\DOMElement $header): ?array
    {
        foreach ($header->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $mustUnderstand = $child->getAttributeNS(self::ENVELOPE_NS, 'mustUnderstand');
            $known = self::SERVICE_NS === $child->namespaceURI && \in_array($child->localName, self::KNOWN_HEADERS, true);
            if (!$known && \in_array($mustUnderstand, ['1', 'true'], true)) {
                return ['code' => 'MustUnderstand', 'message' => \sprintf('En-tête non pris en charge : « %s ».', $child->localName)];
            }
        }

        return null;
    }

    /** Faute SOAP 1.1 minimale. */
    public static function faultXml(string $code, string $message): string
    {
        $escape = static fn (string $s): string => htmlspecialchars($s, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<SOAP-ENV:Envelope xmlns:SOAP-ENV="'.self::ENVELOPE_NS.'"><SOAP-ENV:Body><SOAP-ENV:Fault>'
            .'<faultcode>SOAP-ENV:'.$escape($code).'</faultcode><faultstring>'.$escape($message).'</faultstring>'
            .'</SOAP-ENV:Fault></SOAP-ENV:Body></SOAP-ENV:Envelope>'."\n";
    }
}
