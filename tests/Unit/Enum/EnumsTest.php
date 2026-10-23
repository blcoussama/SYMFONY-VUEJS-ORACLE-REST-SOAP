<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\IncidentCategory;
use App\Enum\IncidentEventType;
use App\Enum\IncidentPriority;
use App\Enum\IncidentSource;
use App\Enum\IncidentStatus;
use App\Enum\SiteType;
use App\Enum\UserRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnumsTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<\BackedEnum>}>
     */
    public static function enums(): iterable
    {
        foreach ([IncidentStatus::class, IncidentPriority::class, IncidentCategory::class, SiteType::class, IncidentEventType::class, IncidentSource::class, UserRole::class] as $class) {
            yield (new \ReflectionClass($class))->getShortName() => [$class];
        }
    }

    /**
     * @param class-string<\BackedEnum> $class
     */
    #[DataProvider('enums')]
    public function testEveryCaseHasAFrenchLabelAndAShortValue(string $class): void
    {
        foreach ($class::cases() as $case) {
            self::assertTrue(method_exists($case, 'label'));
            /** @var string $label */
            $label = $case->label();
            self::assertNotSame('', $label);
            self::assertLessThanOrEqual(30, \strlen((string) $case->value), 'Valeur stockée trop longue pour sa colonne');
        }
    }

    public function testSpecValuesAreExact(): void
    {
        self::assertSame(['NOUVEAU', 'QUALIFIE', 'ASSIGNE', 'EN_COURS', 'EN_ATTENTE', 'RESOLU', 'CLOS', 'ANNULE'], array_column(IncidentStatus::cases(), 'value'));
        self::assertSame(['PANNE_ENERGIE', 'COUPURE_FIBRE', 'EQUIPEMENT', 'RADIO', 'LOGICIEL', 'AUTRE'], array_column(IncidentCategory::cases(), 'value'));
        self::assertSame(['ANTENNE_4G', 'ANTENNE_5G', 'CENTRAL', 'FIBRE_NRO', 'DATACENTER'], array_column(SiteType::cases(), 'value'));
        self::assertSame(['CREATION', 'TRANSITION', 'ASSIGNATION', 'COMMENTAIRE', 'PRIORITE'], array_column(IncidentEventType::cases(), 'value'));
    }

    public function testOpenStatuses(): void
    {
        self::assertSame([IncidentStatus::Nouveau, IncidentStatus::Qualifie, IncidentStatus::Assigne, IncidentStatus::EnCours, IncidentStatus::EnAttente], IncidentStatus::openStatuses());
        self::assertFalse(IncidentStatus::Annule->isOpen());
    }
}
