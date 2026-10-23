<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\IncidentStatus;
use App\Workflow\IncidentTransition;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/** Le Workflow configuré correspond exactement au cycle de vie de la SPEC §3. */
final class WorkflowDefinitionTest extends KernelTestCase
{
    private static function workflow(): WorkflowInterface
    {
        self::bootKernel();
        $workflow = self::getContainer()->get('state_machine.incident');
        \assert($workflow instanceof WorkflowInterface);

        return $workflow;
    }

    public function testPlacesAreTheIncidentStatuses(): void
    {
        $definition = self::workflow()->getDefinition();

        self::assertSame(array_column(IncidentStatus::cases(), 'value'), array_values($definition->getPlaces()));
        self::assertSame(['NOUVEAU'], $definition->getInitialPlaces());
    }

    public function testTransitionsMatchTheSpecification(): void
    {
        $edges = [];
        foreach (self::workflow()->getDefinition()->getTransitions() as $transition) {
            $edges[] = $transition->getName().':'.$transition->getFroms()[0].'>'.$transition->getTos()[0];
        }

        self::assertSame([
            'qualifier:NOUVEAU>QUALIFIE',
            'assigner:QUALIFIE>ASSIGNE',
            'demarrer:ASSIGNE>EN_COURS',
            'mettre_en_attente:EN_COURS>EN_ATTENTE',
            'reprendre:EN_ATTENTE>EN_COURS',
            'resoudre:EN_COURS>RESOLU',
            'clore:RESOLU>CLOS',
            'rouvrir:RESOLU>ASSIGNE',
            'annuler:NOUVEAU>ANNULE',
            'annuler:QUALIFIE>ANNULE',
        ], $edges);
    }

    public function testEveryTransitionHasAnActorRule(): void
    {
        foreach (self::workflow()->getDefinition()->getTransitions() as $transition) {
            self::assertTrue(IncidentTransition::exists($transition->getName()));
        }
        self::assertCount(9, IncidentTransition::all());
    }

    public function testTerminalPlacesHaveNoOutgoingTransition(): void
    {
        foreach (self::workflow()->getDefinition()->getTransitions() as $transition) {
            self::assertNotContains($transition->getFroms()[0], ['CLOS', 'ANNULE']);
        }
    }
}
