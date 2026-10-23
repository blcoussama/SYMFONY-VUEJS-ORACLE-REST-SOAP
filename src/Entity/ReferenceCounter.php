<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Compteur de références lisibles (une ligne par préfixe et par année, ex. « INC-2026 »).
 *
 * Mis à jour en SQL par App\Service\Reference\IncidentReferenceGenerator (UPDATE ... +1
 * dans la transaction de création : le verrou de ligne sérialise les créations concurrentes,
 * sur Oracle comme sur PostgreSQL ; SQLite verrouille la base entière en écriture).
 * Le mapping sert à la création du schéma et aux migrations.
 */
#[ORM\Entity]
#[ORM\Table(name: 'nop_ref_counter')]
class ReferenceCounter
{
    #[ORM\Id]
    #[ORM\Column(name: 'counter_key', length: 20)]
    private string $counterKey;

    #[ORM\Column(name: 'current_value')]
    private int $currentValue;

    public function __construct(string $counterKey, int $currentValue = 0)
    {
        $this->counterKey = $counterKey;
        $this->currentValue = $currentValue;
    }

    public function getCounterKey(): string
    {
        return $this->counterKey;
    }

    public function getCurrentValue(): int
    {
        return $this->currentValue;
    }
}
