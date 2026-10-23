<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\ProblemResponseFactory;
use App\Entity\ApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authentifie un partenaire par l'en-tête X-API-Key (pare-feu « api_key », sans session).
 * La clé est comparée par son empreinte SHA-256 (fournisseur « api_client_provider »).
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const HEADER = 'X-API-Key';

    public function __construct(
        private readonly ProblemResponseFactory $problems,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->headers->has(self::HEADER);
    }

    public function authenticate(Request $request): Passport
    {
        $key = trim((string) $request->headers->get(self::HEADER, ''));
        if ('' === $key) {
            throw new CustomUserMessageAuthenticationException('En-tête X-API-Key vide.');
        }

        return new SelfValidatingPassport(new UserBadge(ApiKeyHasher::hash($key)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $client = $token->getUser();
        if ($client instanceof ApiClient) {
            $client->markUsed($this->clock->now());
            $this->entityManager->flush();
        }

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $detail = $exception instanceof CustomUserMessageAuthenticationException || $exception instanceof CustomUserMessageAccountStatusException
            ? $exception->getMessageKey()
            : 'Clé d’API invalide.';

        return $this->problems->create(Response::HTTP_UNAUTHORIZED, $detail, ['WWW-Authenticate' => 'ApiKey header="X-API-Key"']);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->problems->create(Response::HTTP_UNAUTHORIZED, 'Authentification requise : en-tête X-API-Key ou session.', ['WWW-Authenticate' => 'ApiKey header="X-API-Key"']);
    }
}
