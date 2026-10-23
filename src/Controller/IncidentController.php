<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\IncidentDraft;
use App\Entity\Incident;
use App\Entity\User;
use App\Enum\IncidentPriority;
use App\Enum\IncidentStatus;
use App\Exception\BusinessRuleException;
use App\Exception\IncidentConflictException;
use App\Exception\TransitionRefusedException;
use App\Exception\UnknownTransitionException;
use App\Form\AssignType;
use App\Form\CommentType;
use App\Form\IncidentType;
use App\Form\InterventionPlanType;
use App\Form\PriorityType;
use App\Repository\IncidentRepository;
use App\Repository\SiteRepository;
use App\Repository\UserRepository;
use App\Security\Voter\IncidentVoter;
use App\Service\ActorResolver;
use App\Service\IncidentFilterFactory;
use App\Service\IncidentManager;
use App\Workflow\IncidentActions;
use App\Workflow\IncidentTransition;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages web des incidents : liste filtrée (rechargement AJAX), déclaration, détail et actions.
 * Contrôleur fin : la logique est dans IncidentManager, les droits dans les Voters et le Workflow.
 */
#[Route('/incidents')]
final class IncidentController extends AbstractController
{
    private const REFERENCE = 'INC-\d{4}-\d{6}';

    public function __construct(
        private readonly IncidentRepository $incidents,
        private readonly IncidentManager $manager,
        private readonly ActorResolver $actorResolver,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'app_incident_index', methods: ['GET'])]
    public function index(Request $request, IncidentFilterFactory $filterFactory, SiteRepository $sites, UserRepository $users): Response
    {
        $filter = $filterFactory->fromRequest($request);
        $page = $this->incidents->search($filter, $this->clock->now());

        // Requête AJAX (jQuery) : seul le bloc des résultats est renvoyé.
        $template = $request->isXmlHttpRequest() ? 'incident/_results.html.twig' : 'incident/index.html.twig';

        return $this->render($template, [
            'page' => $page,
            'filter' => $filter,
            'query' => $request->query->all(),
            'regions' => $sites->findRegions(),
            'sites' => $sites->findActive($filter->region),
            'technicians' => $users->findActiveTechnicians(),
            'statuses' => IncidentStatus::cases(),
            'priorities' => IncidentPriority::cases(),
            'open_value' => IncidentFilterFactory::OPEN,
        ]);
    }

    #[Route('/declarer', name: 'app_incident_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $draft = new IncidentDraft();
        $form = $this->createForm(IncidentType::class, $draft);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $incident = $this->manager->declare($draft, $this->actorResolver->current());
            $this->addFlash('success', \sprintf('Incident %s déclaré. Échéance SLA : %s.', $incident->getReference(), $incident->getSlaDueAt()->setTimezone(new \DateTimeZone('Africa/Casablanca'))->format('d/m/Y H:i')));

            return $this->redirectToRoute('app_incident_show', ['reference' => $incident->getReference()]);
        }

        return $this->render('incident/new.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{reference}', name: 'app_incident_show', requirements: ['reference' => self::REFERENCE], methods: ['GET'])]
    public function show(string $reference, IncidentActions $actions): Response
    {
        $incident = $this->findIncident($reference);
        $this->denyAccessUnlessGranted(IncidentVoter::VIEW, $incident);

        return $this->renderShow($incident, $actions);
    }

    #[Route('/{reference}/transition/{transition}', name: 'app_incident_transition', requirements: ['reference' => self::REFERENCE, 'transition' => '[a-z_]+'], methods: ['POST'])]
    public function transition(string $reference, string $transition, Request $request): Response
    {
        $incident = $this->findIncident($reference);
        $this->denyAccessUnlessGranted(IncidentVoter::VIEW, $incident);
        if (!$this->isCsrfTokenValid('incident-'.$incident->getReference(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        try {
            $this->manager->applyTransition(
                $incident,
                $transition,
                $this->actorResolver->current(),
                $request->request->getString('comment'),
                self::version($request->request->getString('version')),
                ['report' => $request->request->getString('report')],
            );
            $this->addFlash('success', \sprintf('%s : %s.', $incident->getReference(), $incident->getStatus()->label()));
        } catch (UnknownTransitionException $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        } catch (TransitionRefusedException $e) {
            if ($e->isForbidden()) {
                throw $this->createAccessDeniedException($e->getMessage(), $e);
            }
            $this->addFlash('danger', 'Action refusée : '.$e->getMessage());
        } catch (IncidentConflictException|BusinessRuleException $e) {
            $this->addFlash('warning', $e->getMessage());
        }

        return $this->redirectToRoute('app_incident_show', ['reference' => $incident->getReference()]);
    }

    #[Route('/{reference}/assigner', name: 'app_incident_assign', requirements: ['reference' => self::REFERENCE], methods: ['POST'])]
    public function assign(string $reference, Request $request, IncidentActions $actions): Response
    {
        $incident = $this->findIncident($reference);
        $this->denyAccessUnlessGranted(IncidentVoter::ASSIGN, $incident);

        $form = $this->createForm(AssignType::class);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderShow($incident, $actions, ['assign' => $form], 422);
        }

        $technician = $form->get('technician')->getData();
        \assert($technician instanceof User);
        try {
            $this->manager->assign($incident, $technician, $this->actorResolver->current(), self::string($form, 'comment'), self::version(self::string($form, 'version')));
            $this->addFlash('success', \sprintf('%s assigné à %s.', $incident->getReference(), $technician->getFullName()));
        } catch (TransitionRefusedException|IncidentConflictException|BusinessRuleException $e) {
            $this->addFlash('warning', $e->getMessage());
        }

        return $this->redirectToRoute('app_incident_show', ['reference' => $incident->getReference()]);
    }

    /** Qualification (NOUVEAU → QUALIFIE avec priorité confirmée) ou changement de priorité. */
    #[Route('/{reference}/priorite', name: 'app_incident_priority', requirements: ['reference' => self::REFERENCE], methods: ['POST'])]
    public function priority(string $reference, Request $request, IncidentActions $actions): Response
    {
        $incident = $this->findIncident($reference);
        $this->denyAccessUnlessGranted(IncidentVoter::PRIORITIZE, $incident);

        $form = $this->createForm(PriorityType::class);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderShow($incident, $actions, ['priority' => $form], 422);
        }

        $priority = $form->get('priority')->getData();
        \assert($priority instanceof IncidentPriority);
        $actor = $this->actorResolver->current();
        $comment = self::string($form, 'comment');
        $version = self::version(self::string($form, 'version'));
        try {
            if (IncidentStatus::Nouveau === $incident->getStatus()) {
                $this->manager->applyTransition($incident, IncidentTransition::QUALIFIER, $actor, $comment, $version, ['priority' => $priority]);
                $this->addFlash('success', \sprintf('%s qualifié en %s.', $incident->getReference(), $priority->value));
            } else {
                $this->manager->changePriority($incident, $priority, $actor, $comment, $version);
                $this->addFlash('success', \sprintf('Priorité de %s : %s.', $incident->getReference(), $priority->value));
            }
        } catch (TransitionRefusedException $e) {
            if ($e->isForbidden()) {
                throw $this->createAccessDeniedException($e->getMessage(), $e);
            }
            $this->addFlash('danger', $e->getMessage());
        } catch (IncidentConflictException|BusinessRuleException $e) {
            $this->addFlash('warning', $e->getMessage());
        }

        return $this->redirectToRoute('app_incident_show', ['reference' => $incident->getReference()]);
    }

    #[Route('/{reference}/commentaire', name: 'app_incident_comment', requirements: ['reference' => self::REFERENCE], methods: ['POST'])]
    public function comment(string $reference, Request $request, IncidentActions $actions): Response
    {
        $incident = $this->findIncident($reference);
        $this->denyAccessUnlessGranted(IncidentVoter::COMMENT, $incident);

        $form = $this->createForm(CommentType::class);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderShow($incident, $actions, ['comment' => $form], 422);
        }

        $this->manager->comment($incident, (string) self::string($form, 'text'), $this->actorResolver->current());
        $this->addFlash('success', 'Commentaire ajouté.');

        return $this->redirect($this->generateUrl('app_incident_show', ['reference' => $incident->getReference()]).'#journal');
    }

    /**
     * @param array<string, FormInterface<mixed>> $submitted formulaires soumis invalides à réafficher
     */
    private function renderShow(Incident $incident, IncidentActions $actions, array $submitted = [], int $status = 200): Response
    {
        $version = ['version' => (string) $incident->getVersion()];
        $nextHour = $this->clock->now()->modify('+1 hour');
        $nextHour = $nextHour->setTime((int) $nextHour->format('H'), 0);
        $forms = [
            'assign' => $submitted['assign'] ?? $this->createForm(AssignType::class, $version + ['technician' => $incident->getAssignee()], [
                'action' => $this->generateUrl('app_incident_assign', ['reference' => $incident->getReference()]),
            ]),
            'priority' => $submitted['priority'] ?? $this->createForm(PriorityType::class, $version + ['priority' => $incident->getPriority()], [
                'action' => $this->generateUrl('app_incident_priority', ['reference' => $incident->getReference()]),
            ]),
            'comment' => $submitted['comment'] ?? $this->createForm(CommentType::class, null, [
                'action' => $this->generateUrl('app_incident_comment', ['reference' => $incident->getReference()]),
            ]),
            'plan' => $this->createForm(InterventionPlanType::class, ['plannedAt' => $nextHour], [
                'action' => $this->generateUrl('app_intervention_plan', ['reference' => $incident->getReference()]),
            ]),
        ];

        return $this->render('incident/show.html.twig', [
            'incident' => $incident,
            'actions' => $actions->available($incident),
            'assign_form' => $forms['assign'],
            'priority_form' => $forms['priority'],
            'comment_form' => $forms['comment'],
            'plan_form' => $forms['plan'],
        ], new Response(status: $status));
    }

    private function findIncident(string $reference): Incident
    {
        return $this->incidents->findOneByReference($reference)
            ?? throw $this->createNotFoundException(\sprintf('Incident « %s » introuvable.', $reference));
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private static function string(FormInterface $form, string $field): ?string
    {
        $value = $form->get($field)->getData();

        return \is_scalar($value) ? (string) $value : null;
    }

    private static function version(?string $value): ?int
    {
        $value = trim((string) $value);

        return '' === $value ? null : (ctype_digit($value) ? (int) $value : -1);
    }
}
