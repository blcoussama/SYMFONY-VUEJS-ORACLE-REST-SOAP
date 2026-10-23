<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Entity\Intervention;
use App\Enum\IncidentStatus;
use App\Tests\Support\WebTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InterventionWebTest extends WebTestCase
{
    use WebTestHelpers;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::loginAs($this->client, AppFixtures::TECHNICIAN_1);
    }

    public function testMyInterventionsListsAssignedIncidents(): void
    {
        $crawler = $this->client->request('GET', '/mes-interventions');

        self::assertResponseIsSuccessful();
        $assigned = $crawler->filter('#assigned-title + .row article')->count();
        self::assertGreaterThan(0, $assigned);
        self::assertSelectorTextContains('body', 'Karim');
    }

    public function testPlanStartAndFinishAnIntervention(): void
    {
        $incident = self::incidentIn(IncidentStatus::Assigne, AppFixtures::TECHNICIAN_1);
        $crawler = $this->client->request('GET', '/mes-interventions');

        $form = $crawler->filter('form[action="/incidents/'.$incident->getReference().'/interventions"]')->form();
        $form['plan_'.$incident->getId().'[plannedAt]'] = (new \DateTimeImmutable('+2 hours', new \DateTimeZone('Africa/Casablanca')))->format('d/m/Y H:i');
        $this->client->submit($form);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Intervention planifiée');

        $incident = self::reload($incident);
        $planned = null;
        foreach ($incident->getInterventions() as $i) {
            if ($i->isPlanned()) {
                $planned = $i;
            }
        }
        self::assertInstanceOf(Intervention::class, $planned);

        $this->client->submit($crawler->filter('form[action="/interventions/'.$planned->getId().'/demarrer"]')->form());
        $crawler = $this->client->followRedirect();
        self::assertSame(IncidentStatus::EnCours, self::reload($incident)->getStatus());

        $this->client->submit($crawler->filter('form[action="/interventions/'.$planned->getId().'/terminer"]')->form([
            'finish_'.$planned->getId().'[report]' => 'Connecteur optique nettoyé et remplacé.',
        ]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Intervention terminée');
        self::assertTrue(self::reload($incident)->hasCompletedInterventionReport());
    }

    public function testPlannedDateMustUseTheFrenchFormat(): void
    {
        $incident = self::incidentIn(IncidentStatus::Assigne, AppFixtures::TECHNICIAN_1);
        $crawler = $this->client->request('GET', '/mes-interventions');
        $form = $crawler->filter('form[action="/incidents/'.$incident->getReference().'/interventions"]')->form();
        $form['plan_'.$incident->getId().'[plannedAt]'] = '2026-12-31T10:00';

        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'jj/mm/aaaa hh:mm');
    }

    public function testFinishRequiresAReport(): void
    {
        $incident = self::incidentIn(IncidentStatus::EnCours, AppFixtures::TECHNICIAN_1);
        $open = $incident->findOpenInterventionOf(self::user(AppFixtures::TECHNICIAN_1));
        self::assertNotNull($open);
        $crawler = $this->client->request('GET', '/mes-interventions');

        $this->client->submit($crawler->filter('form[action="/interventions/'.$open->getId().'/terminer"]')->form(['finish_'.$open->getId().'[report]' => '']));
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'Le compte rendu est obligatoire');
    }

    public function testAnotherTechnicianCannotTouchTheIntervention(): void
    {
        $incident = self::incidentIn(IncidentStatus::EnCours, AppFixtures::TECHNICIAN_1);
        $open = $incident->findOpenInterventionOf(self::user(AppFixtures::TECHNICIAN_1));
        self::assertNotNull($open);

        self::loginAs($this->client, AppFixtures::TECHNICIAN_2);
        $this->client->request('POST', '/interventions/'.$open->getId().'/terminer', ['finish_'.$open->getId() => ['report' => 'Usurpation']]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/incidents/'.$incident->getReference().'/interventions', ['intervention_plan' => ['plannedAt' => '01/01/2030 10:00']]);
        self::assertResponseStatusCodeSame(403);
    }
}
