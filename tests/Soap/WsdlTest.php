<?php

declare(strict_types=1);

namespace App\Tests\Soap;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Le contrat WSDL est servi sans authentification et sans dépendre de ext-soap. */
#[Group('soap')]
final class WsdlTest extends WebTestCase
{
    public function testWsdlIsServedWithTheRealAddress(): void
    {
        $client = static::createClient();
        $client->request('GET', '/soap/incidents?wsdl');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/xml; charset=UTF-8');
        $wsdl = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('__SOAP_LOCATION__', $wsdl);
        self::assertStringContainsString('location="http://localhost/soap/incidents"', $wsdl);

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($wsdl));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('wsdl', 'http://schemas.xmlsoap.org/wsdl/');
        $operations = [];
        foreach ($xpath->query('//wsdl:portType/wsdl:operation/@name') ?: [] as $attr) {
            $operations[] = $attr->nodeValue;
        }
        self::assertSame(['getIncidentStatus', 'createIncident', 'listOpenIncidentsBySite'], $operations);
    }

    public function testVersionedWsdlFileDeclaresTheApiKeyHeaderAndFaults(): void
    {
        $wsdl = (string) file_get_contents(\dirname(__DIR__, 2).'/config/soap/incidents.wsdl');

        self::assertStringContainsString('targetNamespace="urn:netops:incidents:v1"', $wsdl);
        self::assertSame(3, substr_count($wsdl, '<soap:header message="tns:ApiKeyHeader" part="ApiKey" use="literal"/>'));
        self::assertStringContainsString('<xsd:element name="ServiceFault">', $wsdl);
    }

    public function testPlainGetDescribesTheService(): void
    {
        $client = static::createClient();
        $client->request('GET', '/soap/incidents');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('?wsdl', (string) $client->getResponse()->getContent());
    }

    public function testPostWithoutTheSoapExtensionIs503(): void
    {
        if (\extension_loaded('soap')) {
            self::markTestSkipped('ext-soap est chargée : cas couvert par SoapEndpointTest.');
        }
        $client = static::createClient();
        $client->request('POST', '/soap/incidents', [], [], ['CONTENT_TYPE' => 'text/xml'], '<x/>');

        self::assertResponseStatusCodeSame(503);
    }
}
