<?php

declare(strict_types=1);

namespace App\Tests\Soap;

use App\DataFixtures\AppFixtures;
use App\Enum\IncidentStatus;
use App\Tests\Support\FixtureLookup;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Bout en bout SOAP : enveloppes XML réelles traitées par \SoapServer, et client \SoapClient
 * branché sur le noyau Symfony (sans serveur HTTP). Ignoré proprement sans ext-soap.
 */
#[Group('soap')]
#[RequiresPhpExtension('soap')]
final class SoapEndpointTest extends WebTestCase
{
    use FixtureLookup;

    private const NS = 'urn:netops:incidents:v1';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private static function envelope(string $body, ?string $apiKey = AppFixtures::DEMO_API_KEY): string
    {
        $header = null !== $apiKey ? '<soapenv:Header><tns:ApiKey>'.htmlspecialchars($apiKey).'</tns:ApiKey></soapenv:Header>' : '';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tns="'.self::NS.'">'
            .$header.'<soapenv:Body>'.$body.'</soapenv:Body></soapenv:Envelope>';
    }

    /**
     * @param array<string, string> $server
     */
    private function call(string $operation, string $xml, array $server = []): \DOMXPath
    {
        $this->client->request('POST', '/soap/incidents', [], [], $server + [
            'CONTENT_TYPE' => 'text/xml; charset=utf-8',
            'HTTP_SOAPACTION' => '"'.self::NS.'#'.$operation.'"',
        ], $xml);

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML((string) $this->client->getResponse()->getContent()), 'Réponse XML invalide');
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('env', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xpath->registerNamespace('ns', self::NS);

        return $xpath;
    }

    private static function text(\DOMXPath $xpath, string $query): string
    {
        $nodes = $xpath->query($query);
        self::assertNotFalse($nodes);
        self::assertGreaterThan(0, $nodes->length, "Nœud absent : $query");

        return (string) $nodes->item(0)?->nodeValue;
    }

    public function testGetIncidentStatusWithSoapHeader(): void
    {
        $incident = self::incidentIn(IncidentStatus::Assigne);
        $xpath = $this->call('getIncidentStatus', self::envelope('<tns:getIncidentStatus><tns:reference>'.$incident->getReference().'</tns:reference></tns:getIncidentStatus>'));

        self::assertResponseIsSuccessful();
        self::assertSame('ASSIGNE', self::text($xpath, '//ns:getIncidentStatusResponse/ns:incident/ns:status'));
        self::assertSame($incident->getAssignee()?->getFullName(), self::text($xpath, '//ns:incident/ns:technician'));
        self::assertContains(self::text($xpath, '//ns:incident/ns:slaBreached'), ['true', 'false']);
    }

    public function testHttpApiKeyHeaderIsAccepted(): void
    {
        $incident = self::incidentIn(IncidentStatus::Nouveau);
        $xpath = $this->call(
            'getIncidentStatus',
            self::envelope('<tns:getIncidentStatus><tns:reference>'.$incident->getReference().'</tns:reference></tns:getIncidentStatus>', null),
            ['HTTP_X_API_KEY' => AppFixtures::DEMO_API_KEY],
        );

        self::assertResponseIsSuccessful();
        self::assertSame('NOUVEAU', self::text($xpath, '//ns:incident/ns:status'));
    }

    public function testMissingKeyIsAnUnauthorizedFault(): void
    {
        $xpath = $this->call('getIncidentStatus', self::envelope('<tns:getIncidentStatus><tns:reference>INC-2026-000001</tns:reference></tns:getIncidentStatus>', null));

        self::assertResponseStatusCodeSame(500);
        self::assertStringEndsWith('Client.Unauthorized', self::text($xpath, '//env:Fault/faultcode'));
    }

    public function testRevokedKeyIsAnUnauthorizedFault(): void
    {
        $xpath = $this->call('listOpenIncidentsBySite', self::envelope('<tns:listOpenIncidentsBySite><tns:siteCode>TNG-0042</tns:siteCode></tns:listOpenIncidentsBySite>', AppFixtures::REVOKED_API_KEY));

        self::assertStringEndsWith('Client.Unauthorized', self::text($xpath, '//env:Fault/faultcode'));
    }

    public function testUnknownIncidentIsANotFoundFaultWithDetail(): void
    {
        $xpath = $this->call('getIncidentStatus', self::envelope('<tns:getIncidentStatus><tns:reference>INC-2026-999999</tns:reference></tns:getIncidentStatus>'));

        self::assertResponseStatusCodeSame(500);
        self::assertStringEndsWith('Client.NotFound', self::text($xpath, '//env:Fault/faultcode'));
        self::assertStringContainsString('introuvable', self::text($xpath, '//env:Fault/faultstring'));
    }

    public function testCreateIncidentReturnsAReference(): void
    {
        $xpath = $this->call('createIncident', self::envelope(
            '<tns:createIncident><tns:siteCode>FES-0030</tns:siteCode><tns:title>Redresseur en défaut</tns:title>'
            .'<tns:description>Alarme majeure sur le redresseur n°2.</tns:description><tns:category>PANNE_ENERGIE</tns:category>'
            .'<tns:priority>P2</tns:priority></tns:createIncident>'
        ));

        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/^INC-\d{4}-\d{6}$/', self::text($xpath, '//ns:createIncidentResponse/ns:reference'));
    }

    public function testInvalidCreationIsAValidationFault(): void
    {
        $xpath = $this->call('createIncident', self::envelope(
            '<tns:createIncident><tns:siteCode>FES-0030</tns:siteCode><tns:title>Ko</tns:title>'
            .'<tns:description>Trop court</tns:description><tns:category>AUTRE</tns:category><tns:priority>P2</tns:priority></tns:createIncident>'
        ));

        self::assertStringEndsWith('Client.Validation', self::text($xpath, '//env:Fault/faultcode'));
        self::assertStringContainsString('title', self::text($xpath, '//env:Fault/faultstring'));
    }

    public function testListOpenIncidentsBySite(): void
    {
        $xpath = $this->call('listOpenIncidentsBySite', self::envelope('<tns:listOpenIncidentsBySite><tns:siteCode>TNG-0042</tns:siteCode></tns:listOpenIncidentsBySite>'));

        self::assertResponseIsSuccessful();
        $nodes = $xpath->query('//ns:listOpenIncidentsBySiteResponse/ns:incident');
        self::assertNotFalse($nodes);
        self::assertGreaterThan(0, $nodes->length);
        self::assertSame('TNG-0042', self::text($xpath, '//ns:incident/ns:siteCode'));
    }

    public function testMalformedEnvelopeIsAFault(): void
    {
        $this->client->request('POST', '/soap/incidents', [], [], ['CONTENT_TYPE' => 'text/xml'], '<pas-du-soap');

        self::assertResponseStatusCodeSame(500);
        self::assertStringContainsString('Fault', (string) $this->client->getResponse()->getContent());
    }

    public function testUnknownOperationIsAFault(): void
    {
        $xpath = $this->call('deleteEverything', self::envelope('<tns:deleteEverything/>'));

        self::assertResponseStatusCodeSame(500);
        self::assertStringContainsString('Opération inconnue', self::text($xpath, '//env:Fault/faultstring'));
    }

    public function testUnknownMandatoryHeaderIsAMustUnderstandFault(): void
    {
        $xml = str_replace('<soapenv:Header>', '<soapenv:Header><x:Trace xmlns:x="urn:autre" soapenv:mustUnderstand="1">1</x:Trace>', self::envelope('<tns:listOpenIncidentsBySite><tns:siteCode>TNG-0042</tns:siteCode></tns:listOpenIncidentsBySite>'));
        $xpath = $this->call('listOpenIncidentsBySite', $xml);

        self::assertSame('SOAP-ENV:MustUnderstand', self::text($xpath, '//env:Fault/faultcode'));
    }

    public function testRealSoapClientAgainstTheKernel(): void
    {
        $this->client->request('GET', '/soap/incidents?wsdl');
        $wsdlFile = sys_get_temp_dir().'/netops-test-'.bin2hex(random_bytes(4)).'.wsdl';
        file_put_contents($wsdlFile, (string) $this->client->getResponse()->getContent());

        try {
            $soap = new KernelSoapClient($this->client, $wsdlFile);
            $soap->__setSoapHeaders([new \SoapHeader(self::NS, 'ApiKey', AppFixtures::DEMO_API_KEY)]);

            /** @var \stdClass $created */
            $created = $soap->createIncident(['siteCode' => 'AGA-0016', 'title' => 'Coupure secteur à Founty', 'description' => 'Site sur batteries depuis 20 minutes.', 'category' => 'PANNE_ENERGIE', 'priority' => 'P1']);
            self::assertIsObject($created);
            $status = $soap->getIncidentStatus(['reference' => $created->reference]);
            self::assertSame('NOUVEAU', $status->incident->status);
            self::assertSame('P1', $status->incident->priority);

            /** @var \stdClass $list */
            $list = $soap->listOpenIncidentsBySite(['siteCode' => 'AGA-0016']);
            /** @var list<\stdClass> $items */
            $items = $list->incident;
            self::assertContains($created->reference, array_map(static fn (\stdClass $i): string => (string) $i->reference, $items));

            try {
                $soap->getIncidentStatus(['reference' => 'INC-2026-999999']);
                self::fail('Faute attendue');
            } catch (\SoapFault $fault) {
                self::assertStringEndsWith('Client.NotFound', (string) $fault->faultcode);
            }
        } finally {
            @unlink($wsdlFile);
        }
    }
}
