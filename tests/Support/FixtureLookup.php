<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Incident;
use App\Entity\User;
use App\Enum\IncidentStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accès aux données de démonstration (src/DataFixtures/AppFixtures.php) depuis les tests.
 * Nécessite un noyau démarré (KernelTestCase ou WebTestCase).
 */
trait FixtureLookup
{
    protected static function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    protected static function user(string $email): User
    {
        $user = static::em()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user, "Utilisateur de démonstration absent : $email");

        return $user;
    }

    /** Premier incident (par référence) dans ce statut, éventuellement assigné à ce technicien. */
    protected static function incidentIn(IncidentStatus $status, ?string $assigneeEmail = null): Incident
    {
        $criteria = ['status' => $status];
        if (null !== $assigneeEmail) {
            $criteria['assignee'] = static::user($assigneeEmail);
        }
        $incident = static::em()->getRepository(Incident::class)->findOneBy($criteria, ['reference' => 'ASC']);
        self::assertNotNull($incident, "Aucun incident {$status->value} dans les fixtures".($assigneeEmail ? " pour $assigneeEmail" : ''));

        return $incident;
    }

    protected static function reload(Incident $incident): Incident
    {
        $em = static::em();
        $em->clear();
        $fresh = $em->getRepository(Incident::class)->findOneBy(['reference' => $incident->getReference()]);
        self::assertNotNull($fresh);

        return $fresh;
    }
}
