<?php

declare(strict_types=1);

namespace App\Service\Reference;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Attribue le prochain numéro INC-AAAA-NNNNNN, sans doublon même en cas de créations simultanées.
 *
 * Méthode portable (Oracle, PostgreSQL, SQLite) sans séquence propre à une base :
 *   1. UPDATE nop_ref_counter SET current_value = current_value + 1 WHERE counter_key = ?
 *      — l'UPDATE pose un verrou de ligne gardé jusqu'à la fin de la transaction englobante :
 *      une seconde transaction attend, puis lit la valeur suivante ;
 *   2. relecture de la valeur dans la même transaction ;
 *   3. première référence de l'année : INSERT (valeur 1) dans un point de sauvegarde ; si une
 *      autre transaction l'a inséré entre-temps (violation d'unicité), on recommence l'UPDATE.
 *
 * À appeler dans la transaction qui insère l'incident (IncidentManager::declare).
 */
final class IncidentReferenceGenerator
{
    private const TABLE = 'nop_ref_counter';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function next(\DateTimeImmutable $at): string
    {
        $year = (int) $at->format('Y');

        return IncidentReference::format($year, $this->nextValue(IncidentReference::counterKey($year)));
    }

    private function nextValue(string $key): int
    {
        return $this->connection->transactional(static function (Connection $connection) use ($key): int {
            for ($attempt = 1; $attempt <= 3; ++$attempt) {
                $updated = $connection->executeStatement(
                    'UPDATE '.self::TABLE.' SET current_value = current_value + 1 WHERE counter_key = ?',
                    [$key],
                );
                if ($updated > 0) {
                    $value = $connection->fetchOne('SELECT current_value FROM '.self::TABLE.' WHERE counter_key = ?', [$key]);

                    return (int) $value;
                }

                // Transaction imbriquée = point de sauvegarde : un échec n'annule pas la transaction appelante.
                $connection->beginTransaction();
                try {
                    $connection->insert(self::TABLE, ['counter_key' => $key, 'current_value' => 1]);
                    $connection->commit();

                    return 1;
                } catch (UniqueConstraintViolationException) {
                    $connection->rollBack();
                }
            }

            throw new \RuntimeException(\sprintf('Impossible d’attribuer une référence pour « %s ».', $key));
        });
    }
}
