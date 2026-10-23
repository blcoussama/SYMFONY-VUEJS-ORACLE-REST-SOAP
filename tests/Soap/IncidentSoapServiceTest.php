<?php

declare(strict_types=1);

namespace App\Tests\Soap;

use App\DataFixtures\AppFixtures;
use App\Entity\Incident;
use App\Entity\Site;
use App\Enum\IncidentSource;
use App\Enum\IncidentStatus;
use App\Soap\IncidentSoapService;
use App\Soap\SoapServiceException;
use App\Tests\Support\FixtureLookup;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Opérations SOAP testées sans le protocole (aucune dépendance à ext-soap). */
#[Group('soap')]
final class IncidentSoapServiceTest extends KernelTestCase
{
    use FixtureLookup;

    private IncidentSoapService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(IncidentSoapService::class);
        \assert($service instanceof IncidentSoapService);
        $this->service = $service;
    }

    private function expectFault(string $code, callable $call): void
    {
        try {
            $call();
            self::fail("Faute $code attendue.");
        } catch (SoapServiceException $e) {
            self::assertSame($code, $e->faultCode);
        }
    }

    public function testAuthentication(): void
    {
        self::assertSame('OSS Supervision Nord (démo)', $this->service->authenticate(AppFixtures::DEMO_API_KEY)->getName());
        $this->expectFault(SoapServiceException::UNAUTHORIZED, fn () => $this->service->authenticate(null));
        $this->expectFault(SoapServiceException::UNAUTHORIZED, fn () => $this->service->authenticate('nop_inconnue'));
        $this->expectFault(SoapServiceException::UNAUTHORIZED, fn () => $this->service->authenticate(AppFixtures::REVOKED_API_KEY));
    }

    public function testGetIncidentStatus(): void
    {
        $client = $this->service->authenticate(AppFixtures::DEMO_API_KEY);
        $incident = self::incidentIn(IncidentStatus::EnCours);

        $info = $this->service->getIncidentStatus($client, strtolower($incident->getReference()));

        self::assertSame($incident->getReference(), $info['reference']);
        self::assertSame('EN_COURS', $info['status']);
        self::assertSame($incident->getAssignee()?->getFullName(), $info['technician']);
        self::assertMatchesRegularExpression('/Z$/', (string) $info['slaDueAt']);
        self::assertIsBool($info['slaBreached']);
    }

    public function testGetIncidentStatusFaults(): void
    {
        $client = $this->service->authenticate(AppFixtures::DEMO_API_KEY);

        $this->expectFault(SoapServiceException::NOT_FOUND, fn () => $this->service->getIncidentStatus($client, 'INC-2026-999999'));
        $this->expectFault(SoapServiceException::VALIDATION, fn () => $this->service->getIncidentStatus($client, '42'));
    }

    public function testCreateIncident(): void
    {
        $client = $this->service->authenticate(AppFixtures::DEMO_API_KEY);
        $reference = $this->service->createIncident($client, 'cas-0144', 'Antenne 5G hors service', 'Plus aucun trafic sur les trois secteurs.', 'radio', 'p1');

        $incident = self::em()->getRepository(Incident::class)->findOneBy(['reference' => $reference]);
        self::assertNotNull($incident);
        self::assertSame(IncidentSource::Soap, $incident->getSource());
        self::assertSame('CAS-0144', $incident->getSite()->getCode());
        self::assertSame('OSS Supervision Nord (démo) (API SOAP)', $incident->getEvents()->first() ? $incident->getEvents()->first()->getAuthorLabel() : null);
    }

    public function testCreateIncidentValidation(): void
    {
        $client = $this->service->authenticate(AppFixtures::DEMO_API_KEY);

        $this->expectFault(SoapServiceException::VALIDATION, fn () => $this->service->createIncident($client, 'CAS-0144', '', 'x', 'INCENDIE', 'P0'));
        $this->expectFault(SoapServiceException::VALIDATION, fn () => $this->service->createIncident($client, AppFixtures::SITE_INACTIVE, 'Titre valide', 'Description valide.', 'AUTRE', 'P4'));
    }

    public function testListOpenIncidentsBySite(): void
    {
        $client = $this->service->authenticate(AppFixtures::DEMO_API_KEY);
        $site = self::em()->getRepository(Site::class)->findOneBy(['code' => AppFixtures::SITE_TANGER]);
        self::assertNotNull($site);
        $expected = self::em()->getRepository(Incident::class)->count(['site' => $site, 'status' => IncidentStatus::openStatuses()]);

        $list = $this->service->listOpenIncidentsBySite($client, AppFixtures::SITE_TANGER);

        self::assertCount($expected, $list);
        self::assertGreaterThan(0, $expected);
        $priorities = array_column($list, 'priority');
        $sorted = $priorities;
        sort($sorted);
        self::assertSame($sorted, $priorities);
        $this->expectFault(SoapServiceException::NOT_FOUND, fn () => $this->service->listOpenIncidentsBySite($client, 'ZZZ-9999'));
    }
}
