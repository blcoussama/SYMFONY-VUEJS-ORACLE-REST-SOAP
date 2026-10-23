<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    /** Page d'accueil selon le rôle : tableau de bord, interventions ou liste des incidents. */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(): RedirectResponse
    {
        return match (true) {
            $this->isGranted('ROLE_SUPERVISEUR') => $this->redirectToRoute('app_dashboard'),
            $this->isGranted('ROLE_TECHNICIEN') => $this->redirectToRoute('app_intervention_mine'),
            default => $this->redirectToRoute('app_incident_index'),
        };
    }
}
