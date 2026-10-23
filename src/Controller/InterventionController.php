<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Intervention;
use App\Entity\User;
use App\Exception\BusinessRuleException;
use App\Form\InterventionFinishType;
use App\Form\InterventionPlanType;
use App\Repository\IncidentRepository;
use App\Repository\InterventionRepository;
use App\Security\Voter\IncidentVoter;
use App\Security\Voter\InterventionVoter;
use App\Service\ActorResolver;
use App\Service\InterventionManager;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** « Mes interventions » : planifier, démarrer, terminer avec compte rendu (technicien assigné). */
final class InterventionController extends AbstractController
{
    public function __construct(
        private readonly InterventionManager $manager,
        private readonly ActorResolver $actorResolver,
        private readonly ClockInterface $clock,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[Route('/mes-interventions', name: 'app_intervention_mine', methods: ['GET'])]
    #[IsGranted('ROLE_TECHNICIEN')]
    public function mine(#[CurrentUser] User $user, IncidentRepository $incidents, InterventionRepository $interventions): Response
    {
        $assigned = $incidents->findOpenAssignedTo($user);
        $list = $interventions->findForTechnician($user);

        $finishForms = [];
        foreach ($list as $intervention) {
            if ($intervention->isInProgress()) {
                $finishForms[(int) $intervention->getId()] = $this->formFactory->createNamed(
                    'finish_'.$intervention->getId(),
                    InterventionFinishType::class,
                    null,
                    ['action' => $this->generateUrl('app_intervention_finish', ['id' => $intervention->getId()])],
                )->createView();
            }
        }
        $nextHour = $this->clock->now()->modify('+1 hour');
        $nextHour = $nextHour->setTime((int) $nextHour->format('H'), 0);
        $planForms = [];
        foreach ($assigned as $incident) {
            $planForms[$incident->getReference()] = $this->formFactory->createNamed(
                'plan_'.$incident->getId(),
                InterventionPlanType::class,
                ['plannedAt' => $nextHour],
                ['action' => $this->generateUrl('app_intervention_plan', ['reference' => $incident->getReference()])],
            )->createView();
        }

        return $this->render('intervention/mine.html.twig', [
            'assigned' => $assigned,
            'interventions' => $list,
            'finish_forms' => $finishForms,
            'plan_forms' => $planForms,
        ]);
    }

    #[Route('/incidents/{reference}/interventions', name: 'app_intervention_plan', requirements: ['reference' => 'INC-\d{4}-\d{6}'], methods: ['POST'])]
    public function plan(string $reference, Request $request, IncidentRepository $incidents, #[CurrentUser] User $user): Response
    {
        $incident = $incidents->findOneByReference($reference) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(IncidentVoter::WORK, $incident);

        // Le formulaire peut venir de la page détail (nom par défaut) ou de « Mes interventions » (nom suffixé).
        $name = $request->request->has('plan_'.$incident->getId()) ? 'plan_'.$incident->getId() : 'intervention_plan';
        $form = $this->formFactory->createNamed($name, InterventionPlanType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plannedAt = $form->get('plannedAt')->getData();
            \assert($plannedAt instanceof \DateTimeImmutable);
            try {
                $this->manager->plan($incident, $user, $plannedAt, $this->actorResolver->current());
                $this->addFlash('success', 'Intervention planifiée.');
            } catch (BusinessRuleException $e) {
                $this->addFlash('warning', $e->getMessage());
            }
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->redirect($this->safeReferer($request) ?? $this->generateUrl('app_intervention_mine'));
    }

    #[Route('/interventions/{id}/demarrer', name: 'app_intervention_start', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function start(#[MapEntity(id: 'id')] Intervention $intervention, Request $request): Response
    {
        $this->denyAccessUnlessGranted(InterventionVoter::EDIT, $intervention);
        if (!$this->isCsrfTokenValid('intervention-'.$intervention->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        try {
            $this->manager->start($intervention, $this->actorResolver->current());
            $this->addFlash('success', \sprintf('Intervention démarrée sur %s.', $intervention->getIncident()->getReference()));
        } catch (BusinessRuleException|\LogicException $e) {
            $this->addFlash('warning', $e->getMessage());
        }

        return $this->redirect($this->safeReferer($request) ?? $this->generateUrl('app_intervention_mine'));
    }

    #[Route('/interventions/{id}/terminer', name: 'app_intervention_finish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function finish(#[MapEntity(id: 'id')] Intervention $intervention, Request $request): Response
    {
        $this->denyAccessUnlessGranted(InterventionVoter::EDIT, $intervention);

        $form = $this->formFactory->createNamed('finish_'.$intervention->getId(), InterventionFinishType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->manager->finish($intervention, (string) $form->get('report')->getData(), $this->actorResolver->current());
                $this->addFlash('success', 'Intervention terminée. Vous pouvez maintenant résoudre l’incident.');
            } catch (\LogicException|\InvalidArgumentException $e) {
                $this->addFlash('warning', $e->getMessage());
            }
        } else {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->redirect($this->safeReferer($request) ?? $this->generateUrl('app_intervention_mine'));
    }

    /** Retour à la page d'origine, seulement si elle est sur ce site (pas de redirection ouverte). */
    private function safeReferer(Request $request): ?string
    {
        $referer = (string) $request->headers->get('referer', '');

        return str_starts_with($referer, $request->getSchemeAndHttpHost().'/') ? $referer : null;
    }
}
