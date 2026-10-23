<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ApiClient;
use App\Entity\Incident;
use App\Entity\Site;
use App\Entity\User;
use App\Enum\IncidentCategory;
use App\Enum\IncidentPriority;
use App\Enum\IncidentSource;
use App\Enum\IncidentStatus;
use App\Enum\SiteType;
use App\Enum\UserRole;

/**
 * Objets du domaine en mémoire pour les tests unitaires (identifiants posés par réflexion).
 */
final class DomainFactory
{
    private static int $nextId = 1000;

    public static function user(UserRole $role, bool $active = true): User
    {
        $user = new User(strtolower($role->name).self::$nextId.'@netops.example', 'Test', $role->name, $role);
        $user->setActive($active);
        self::setId($user, ++self::$nextId);

        return $user;
    }

    public static function incident(IncidentStatus $status = IncidentStatus::Nouveau, ?User $assignee = null, ?ApiClient $client = null): Incident
    {
        $now = new \DateTimeImmutable('2026-03-10 08:00:00');
        $incident = new Incident('INC-2026-000001', 'Coupure fibre de test', 'Description de test.', new Site('TST-0001', 'Site de test', 'Tanger', 'Tanger-Tétouan-Al Hoceïma', SiteType::Antenne4G), IncidentCategory::CoupureFibre, IncidentPriority::P2, IncidentSource::Web, $now, $now->modify('+8 hours'));
        $incident->setStatus($status);
        if (null !== $assignee) {
            $incident->assignTo($assignee, $now);
        }
        if (null !== $client) {
            $incident->declaredBy(null, $client);
        }
        self::setId($incident, ++self::$nextId);

        return $incident;
    }

    public static function apiClient(bool $active = true): ApiClient
    {
        $client = new ApiClient('Partenaire '.self::$nextId, 'nop_test', hash('sha256', 'k'.self::$nextId), new \DateTimeImmutable());
        if (!$active) {
            $client->revoke(new \DateTimeImmutable());
        }
        self::setId($client, ++self::$nextId);

        return $client;
    }

    private static function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
