<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * L'API REST appelée avec le cookie de session (tableau Kanban) exige l'en-tête X-CSRF-Token
 * sur les méthodes d'écriture. Les partenaires (X-API-Key, sans cookie) n'y sont pas soumis.
 */
final class ApiSessionCsrfSubscriber implements EventSubscriberInterface
{
    public const HEADER = 'X-CSRF-Token';
    public const TOKEN_ID = 'api';

    public function __construct(
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Après le pare-feu (priorité 8) : l'utilisateur est connu.
        return [KernelEvents::REQUEST => ['onKernelRequest', 4]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/') || $request->isMethodSafe()) {
            return;
        }
        if (!$this->security->getUser() instanceof User) {
            return;
        }

        $token = new CsrfToken(self::TOKEN_ID, (string) $request->headers->get(self::HEADER, ''));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new AccessDeniedHttpException('Jeton CSRF absent ou invalide (en-tête X-CSRF-Token).');
        }
    }
}
