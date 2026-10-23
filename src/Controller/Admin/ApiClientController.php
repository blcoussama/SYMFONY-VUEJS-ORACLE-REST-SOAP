<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ApiClient;
use App\Exception\BusinessRuleException;
use App\Form\Admin\ApiClientFormType;
use App\Repository\ApiClientRepository;
use App\Service\ApiClientManager;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Clés d'API partenaires : création (clé affichée une seule fois) et révocation. */
#[Route('/admin/cles-api')]
#[IsGranted('ROLE_ADMIN')]
final class ApiClientController extends AbstractController
{
    public function __construct(private readonly ApiClientManager $manager)
    {
    }

    #[Route('', name: 'app_admin_api_client_index', methods: ['GET'])]
    public function index(ApiClientRepository $clients): Response
    {
        return $this->render('admin/api_client/index.html.twig', ['clients' => $clients->findAllOrdered()]);
    }

    #[Route('/nouvelle', name: 'app_admin_api_client_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->createForm(ApiClientFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $created = $this->manager->create((string) $form->get('name')->getData());

                // La clé n'est ni stockée ni mise en session : affichée dans cette réponse uniquement.
                $response = $this->render('admin/api_client/created.html.twig', ['client' => $created['client'], 'key' => $created['key']]);
                $response->headers->set('Cache-Control', 'no-store, private');

                return $response;
            } catch (BusinessRuleException $e) {
                $form->get('name')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('admin/api_client/new.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/revoquer', name: 'app_admin_api_client_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revoke(#[MapEntity(id: 'id')] ApiClient $client, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('revoke-api-client-'.$client->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->manager->revoke($client);
        $this->addFlash('success', \sprintf('Clé du partenaire « %s » révoquée.', $client->getName()));

        return $this->redirectToRoute('app_admin_api_client_index');
    }
}
