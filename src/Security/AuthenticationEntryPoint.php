<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\ProblemResponseFactory;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Pare-feu « main » : redirige vers la page de connexion, sauf pour /api/
 * où l'on répond 401 au format RFC 7807.
 */
final class AuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ProblemResponseFactory $problems,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return $this->problems->create(Response::HTTP_UNAUTHORIZED, 'Authentification requise : en-tête X-API-Key ou session.', ['WWW-Authenticate' => 'ApiKey header="X-API-Key"']);
        }

        if ($request->hasSession() && 'GET' === $request->getMethod() && !$request->isXmlHttpRequest()) {
            $request->getSession()->set('_security.main.target_path', $request->getUri());
        }

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
