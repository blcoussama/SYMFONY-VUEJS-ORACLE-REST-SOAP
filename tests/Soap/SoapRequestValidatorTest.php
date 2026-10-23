<?php

declare(strict_types=1);

namespace App\Tests\Soap;

use App\Soap\SoapRequestValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Filtrage des enveloppes avant \SoapServer (exécutable sans ext-soap). */
#[Group('soap')]
final class SoapRequestValidatorTest extends TestCase
{
    private const ENV = 'xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" xmlns:t="urn:netops:incidents:v1"';

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function envelopes(): iterable
    {
        yield 'valide' => ['<s:Envelope '.self::ENV.'><s:Header><t:ApiKey>k</t:ApiKey></s:Header><s:Body><t:getIncidentStatus/></s:Body></s:Envelope>', null];
        yield 'vide' => ['  ', 'Client'];
        yield 'mal formé' => ['<s:Envelope', 'Client'];
        yield 'pas SOAP 1.1' => ['<Envelope xmlns="http://www.w3.org/2003/05/soap-envelope"><Body/></Envelope>', 'VersionMismatch'];
        yield 'sans Body' => ['<s:Envelope '.self::ENV.'/>', 'Client'];
        yield 'Body vide' => ['<s:Envelope '.self::ENV.'><s:Body/></s:Envelope>', 'Client'];
        yield 'opération inconnue' => ['<s:Envelope '.self::ENV.'><s:Body><t:dropTable/></s:Body></s:Envelope>', 'Client'];
        yield 'mauvais espace de noms' => ['<s:Envelope '.self::ENV.'><s:Body><getIncidentStatus/></s:Body></s:Envelope>', 'Client'];
        yield 'en-tête obligatoire inconnu' => ['<s:Envelope '.self::ENV.'><s:Header><x:T xmlns:x="urn:x" s:mustUnderstand="1"/></s:Header><s:Body><t:createIncident/></s:Body></s:Envelope>', 'MustUnderstand'];
        yield 'DTD refusée' => ['<!DOCTYPE s [<!ENTITY e "x">]><s:Envelope '.self::ENV.'><s:Body><t:createIncident/></s:Body></s:Envelope>', 'Client'];
    }

    #[DataProvider('envelopes')]
    public function testValidation(string $xml, ?string $expectedCode): void
    {
        $result = (new SoapRequestValidator())->validate($xml);

        self::assertSame($expectedCode, $result['code'] ?? null);
    }

    public function testFaultXmlIsEscaped(): void
    {
        $xml = SoapRequestValidator::faultXml('Client', 'Valeur <invalide> & refusée');
        $dom = new \DOMDocument();

        self::assertTrue($dom->loadXML($xml));
        self::assertSame('Valeur <invalide> & refusée', $dom->getElementsByTagName('faultstring')->item(0)?->textContent);
    }
}
