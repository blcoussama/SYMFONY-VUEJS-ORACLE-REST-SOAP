<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Site;
use App\Form\Admin\SiteFormType;
use App\Repository\SiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administration des sites. Un site n'est jamais supprimé s'il porte des incidents : il est désactivé.
 */
#[Route('/admin/sites')]
#[IsGranted('ROLE_ADMIN')]
final class SiteController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'app_admin_site_index', methods: ['GET'])]
    public function index(SiteRepository $sites): Response
    {
        return $this->render('admin/site/index.html.twig', ['sites' => $sites->findAllOrdered()]);
    }

    #[Route('/nouveau', name: 'app_admin_site_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $site = new Site();
        $form = $this->createForm(SiteFormType::class, $site);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($site);
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Site %s créé.', $site->getCode()));

            return $this->redirectToRoute('app_admin_site_index');
        }

        return $this->render('admin/site/form.html.twig', ['form' => $form, 'site' => null], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/modifier', name: 'app_admin_site_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(#[MapEntity(id: 'id')] Site $site, Request $request): Response
    {
        $form = $this->createForm(SiteFormType::class, $site);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Site %s mis à jour.', $site->getCode()));

            return $this->redirectToRoute('app_admin_site_index');
        }

        return $this->render('admin/site/form.html.twig', ['form' => $form, 'site' => $site], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/supprimer', name: 'app_admin_site_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(#[MapEntity(id: 'id')] Site $site, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-site-'.$site->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $used = (int) $this->entityManager->createQuery('SELECT COUNT(i.id) FROM App\Entity\Incident i WHERE i.site = :site')
            ->setParameter('site', $site)
            ->getSingleScalarResult();

        if ($used > 0) {
            $site->setActive(false);
            $this->addFlash('warning', \sprintf('Le site %s porte %d incident(s) : il a été désactivé plutôt que supprimé.', $site->getCode(), $used));
        } else {
            $this->entityManager->remove($site);
            $this->addFlash('success', \sprintf('Site %s supprimé.', $site->getCode()));
        }
        $this->entityManager->flush();

        return $this->redirectToRoute('app_admin_site_index');
    }
}
