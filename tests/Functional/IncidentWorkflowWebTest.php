<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Entity\Incident;
use App\Enum\IncidentEventType;
use App\Enum\IncidentStatus;
use App\Tests\Support\WebTestHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Transitions depuis la page détail : boutons selon le rôle, CSRF, refus explicites. */
final class IncidentWorkflowWebTest extends WebTestCase
{
    use WebTestHelpers;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function show(Incident $incident, string $as): Crawler
    {
        self::loginAs($this->client, $as);
        $crawler = $this->client->request('GET', '/incidents/'.$incident->getReference());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function clickAction(Crawler $crawler, string $transition): void
    {
        $this->client->submit($crawler->filter("[data-testid=\"action-$transition\"]")->form());
    }

    public function testAgentSeesNoWorkflowAction(): void
    {
        $this->show(self::incidentIn(IncidentStatus::Nouveau), AppFixtures::AGENT_1);

        self::assertSelectorNotExists('[data-testid^="action-"]');
        self::assertSelectorTextContains('body', 'Aucune action disponible');
        self::assertSelectorExists('#journal');
    }

    public function testSupervisorQualifiesWithAPriority(): void
    {
        $incident = self::incidentIn(IncidentStatus::Nouveau);
        $crawler = $this->show($incident, AppFixtures::SUPERVISOR);
        self::assertSelectorExists('[data-testid="action-annuler"]');

        $this->client->submit($crawler->filter('[data-testid="action-qualifier"]')->form(['priority[priority]' => 'P1']));
        self::assertResponseRedirects('/incidents/'.$incident->getReference());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-success', 'qualifié en P1');
        $incident = self::reload($incident);
        self::assertSame(IncidentStatus::Qualifie, $incident->getStatus());
        self::assertSame('P1', $incident->getPriority()->value);
    }

    public function testSupervisorAssignsATechnician(): void
    {
        $incident = self::incidentIn(IncidentStatus::Qualifie);
        $crawler = $this->show($incident, AppFixtures::SUPERVISOR);
        $technician = self::user(AppFixtures::TECHNICIAN_2);

        $this->client->submit($crawler->filter('[data-testid="action-assigner"]')->form(['assign[technician]' => (string) $technician->getId()]));
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-success', 'assigné à Imane Chraibi');
        $incident = self::reload($incident);
        self::assertSame(IncidentStatus::Assigne, $incident->getStatus());
        self::assertSame($technician->getId(), $incident->getAssignee()?->getId());
    }

    public function testAssignedTechnicianStartsAndResolvesWithAReport(): void
    {
        $incident = self::incidentIn(IncidentStatus::Assigne, AppFixtures::TECHNICIAN_1);
        $crawler = $this->show($incident, AppFixtures::TECHNICIAN_1);
        self::assertSelectorNotExists('[data-testid="action-clore"]');

        $this->clickAction($crawler, 'demarrer');
        $crawler = $this->client->followRedirect();
        self::assertSame(IncidentStatus::EnCours, self::reload($incident)->getStatus());

        $this->client->submit($crawler->filter('[data-testid="action-resoudre"]')->form(['report' => 'Remplacement du module radio.']));
        $this->client->followRedirect();

        $incident = self::reload($incident);
        self::assertSame(IncidentStatus::Resolu, $incident->getStatus());
        self::assertTrue($incident->hasCompletedInterventionReport());
        self::assertSelectorTextContains('section[aria-labelledby="interventions-title"]', 'Remplacement du module radio');
    }

    public function testResolveWithoutReportShowsAClearMessage(): void
    {
        $incident = self::incidentIn(IncidentStatus::EnCours, AppFixtures::TECHNICIAN_1);
        $crawler = $this->show($incident, AppFixtures::TECHNICIAN_1);

        $form = $crawler->filter('[data-testid="action-resoudre"]')->form();
        $this->client->request('POST', $form->getUri(), ['_token' => $form->getValues()['_token'], 'version' => $form->getValues()['version'], 'report' => '']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'compte rendu d’intervention est obligatoire');
        self::assertSame(IncidentStatus::EnCours, self::reload($incident)->getStatus());
    }

    public function testAnotherTechnicianGets403(): void
    {
        $incident = self::incidentIn(IncidentStatus::Assigne, AppFixtures::TECHNICIAN_1);
        self::loginAs($this->client, AppFixtures::TECHNICIAN_2);
        $crawler = $this->client->request('GET', '/incidents/'.$incident->getReference());
        self::assertSelectorNotExists('[data-testid="action-demarrer"]');

        // Formulaire forgé avec un jeton valide de sa propre session : refusé par la garde du Workflow.
        $token = (string) $crawler->filter('form[action$="/commentaire"] input[name="comment[_token]"]')->attr('value');
        $this->client->request('POST', '/incidents/'.$incident->getReference().'/transition/demarrer', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTransitionRequiresAValidCsrfToken(): void
    {
        $incident = self::incidentIn(IncidentStatus::Nouveau);
        self::loginAs($this->client, AppFixtures::SUPERVISOR);
        $this->client->request('POST', '/incidents/'.$incident->getReference().'/transition/annuler', ['_token' => 'forgé']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(IncidentStatus::Nouveau, self::reload($incident)->getStatus());
    }

    public function testImpossibleTransitionIsExplained(): void
    {
        $incident = self::incidentIn(IncidentStatus::Nouveau);
        $crawler = $this->show($incident, AppFixtures::SUPERVISOR);
        $form = $crawler->filter('[data-testid="action-annuler"]')->form();

        $this->client->request('POST', '/incidents/'.$incident->getReference().'/transition/clore', ['_token' => $form->getValues()['_token']]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'pas possible dans le statut actuel');
    }

    public function testUnknownTransitionIs404(): void
    {
        $incident = self::incidentIn(IncidentStatus::Nouveau);
        $crawler = $this->show($incident, AppFixtures::SUPERVISOR);
        $token = $crawler->filter('[data-testid="action-annuler"]')->form()->getValues()['_token'];

        $this->client->request('POST', '/incidents/'.$incident->getReference().'/transition/teleporter', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testStaleFormIsRejectedByOptimisticLocking(): void
    {
        $incident = self::incidentIn(IncidentStatus::Nouveau);
        $crawler = $this->show($incident, AppFixtures::SUPERVISOR);
        $form = $crawler->filter('[data-testid="action-annuler"]')->form();
        $form['version'] = (string) ($incident->getVersion() + 5);

        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-warning', 'modifié entre-temps');
        self::assertSame(IncidentStatus::Nouveau, self::reload($incident)->getStatus());
    }

    public function testCommentIsAddedToTheJournal(): void
    {
        $incident = self::incidentIn(IncidentStatus::EnCours);
        $crawler = $this->show($incident, AppFixtures::AGENT_2);

        $this->client->submit($crawler->selectButton('Commenter')->form(['comment[text]' => 'Client entreprise rappelé.']));
        $this->client->followRedirect();

        self::assertSelectorTextContains('#journal-section', 'Client entreprise rappelé.');
        $last = self::reload($incident)->getEvents()->last();
        self::assertNotFalse($last);
        self::assertSame(IncidentEventType::Commentaire, $last->getType());
    }

    public function testEmptyCommentIsRejected(): void
    {
        $incident = self::incidentIn(IncidentStatus::EnCours);
        $crawler = $this->show($incident, AppFixtures::AGENT_2);

        $this->client->submit($crawler->selectButton('Commenter')->form(['comment[text]' => '  ']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', 'Le commentaire est vide');
    }

    public function testUnknownIncidentIs404(): void
    {
        self::loginAs($this->client, AppFixtures::AGENT_1);
        $this->client->request('GET', '/incidents/INC-2026-999999');

        self::assertResponseStatusCodeSame(404);
    }
}
