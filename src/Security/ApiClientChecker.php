<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiClient;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Une clé révoquée est refusée (401). */
final class ApiClientChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof ApiClient && !$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('Clé d’API révoquée.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
