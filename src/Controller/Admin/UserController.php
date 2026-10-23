<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\Admin\UserFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Administration des utilisateurs : création, rôle, activation. */
#[Route('/admin/utilisateurs')]
#[IsGranted('ROLE_ADMIN')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('', name: 'app_admin_user_index', methods: ['GET'])]
    public function index(UserRepository $users): Response
    {
        return $this->render('admin/user/index.html.twig', ['users' => $users->findAllOrdered()]);
    }

    #[Route('/nouveau', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = new User();
        $form = $this->createForm(UserFormType::class, $user, ['require_password' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyPassword($user, $form);
            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Compte %s créé.', $user->getEmail()));

            return $this->redirectToRoute('app_admin_user_index');
        }

        return $this->render('admin/user/form.html.twig', ['form' => $form, 'user' => null], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/modifier', name: 'app_admin_user_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(#[MapEntity(id: 'id')] User $user, Request $request, #[CurrentUser] User $admin): Response
    {
        $form = $this->createForm(UserFormType::class, $user, ['require_password' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($user->getId() === $admin->getId() && (!$user->isActive() || !\in_array('ROLE_ADMIN', $user->getRoles(), true))) {
                $this->entityManager->refresh($user);
                $this->addFlash('danger', 'Vous ne pouvez pas désactiver votre propre compte ni retirer votre rôle d’administrateur.');

                return $this->redirectToRoute('app_admin_user_edit', ['id' => $user->getId()]);
            }
            $this->applyPassword($user, $form);
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Compte %s mis à jour.', $user->getEmail()));

            return $this->redirectToRoute('app_admin_user_index');
        }

        return $this->render('admin/user/form.html.twig', ['form' => $form, 'user' => $user], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/activation', name: 'app_admin_user_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(#[MapEntity(id: 'id')] User $user, Request $request, #[CurrentUser] User $admin): Response
    {
        if (!$this->isCsrfTokenValid('toggle-user-'.$user->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        if ($user->getId() === $admin->getId()) {
            $this->addFlash('danger', 'Vous ne pouvez pas désactiver votre propre compte.');

            return $this->redirectToRoute('app_admin_user_index');
        }

        $user->setActive(!$user->isActive());
        $this->entityManager->flush();
        $this->addFlash('success', \sprintf('Compte %s %s.', $user->getEmail(), $user->isActive() ? 'activé' : 'désactivé'));

        return $this->redirectToRoute('app_admin_user_index');
    }

    /**
     * @param FormInterface<User> $form
     */
    private function applyPassword(User $user, FormInterface $form): void
    {
        $plain = $form->get('plainPassword')->getData();
        if (\is_string($plain) && '' !== $plain) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $plain));
        }
    }
}
