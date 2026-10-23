<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\IncidentStatus;
use App\EventSubscriber\ApiSessionCsrfSubscriber;
use App\Repository\UserRepository;
use App\Workflow\IncidentActions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tableau Kanban (Vue.js 3 chargé par l'importmap) : la page ne contient que la configuration ;
 * les cartes sont lues et déplacées via l'API REST /api/v1 avec la session et l'en-tête X-CSRF-Token.
 */
final class KanbanController extends AbstractController
{
    #[Route('/kanban', name: 'app_kanban', methods: ['GET'])]
    public function __invoke(IncidentActions $actions, UserRepository $users): Response
    {
        $columns = array_map(static fn (IncidentStatus $s): array => ['status' => $s->value, 'label' => $s->label()], IncidentStatus::cases());

        return $this->render('kanban/index.html.twig', [
            'config' => [
                'apiBase' => $this->generateUrl('api_incidents_list'),
                'incidentUrl' => $this->generateUrl('app_incident_show', ['reference' => 'INC-0000-000000']),
                'csrfHeader' => ApiSessionCsrfSubscriber::HEADER,
                'columns' => $columns,
                'transitions' => $actions->definition(),
                'technicians' => array_map(static fn (User $u): array => ['email' => $u->getEmail(), 'name' => $u->getFullName()], $users->findActiveTechnicians()),
            ],
            'csrf_token_id' => ApiSessionCsrfSubscriber::TOKEN_ID,
        ]);
    }
}
