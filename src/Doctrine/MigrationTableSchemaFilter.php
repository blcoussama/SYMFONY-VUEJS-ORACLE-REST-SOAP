<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\DBAL\Schema\AbstractNamedObject;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\ORM\Tools\Console\Command\SchemaTool\UpdateCommand;
use Doctrine\ORM\Tools\Console\Command\ValidateSchemaCommand;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Masque la table de suivi des migrations pendant doctrine:schema:validate et doctrine:schema:update,
 * sans tenir compte de la casse.
 *
 * DoctrineMigrationsBundle fait déjà ce travail, mais en comparant les noms à la casse près : Oracle
 * renvoie les identifiants non quotés en MAJUSCULES (NOP_MIGRATION_VERSIONS), la table n'était donc
 * pas reconnue et schema:validate concluait à tort à un schéma désynchronisé (« DROP TABLE
 * NOP_MIGRATION_VERSIONS »). Comme le filtre du bundle, celui-ci n'agit que pendant ces deux
 * commandes : les commandes de migration doivent continuer à voir la table.
 */
#[AutoconfigureTag('doctrine.dbal.schema_filter')]
#[AsEventListener(event: ConsoleEvents::COMMAND, method: 'onConsoleCommand')]
final class MigrationTableSchemaFilter
{
    private bool $enabled = false;

    public function __construct(private readonly string $tableName = 'nop_migration_versions')
    {
    }

    /** @param AbstractAsset<OptionallyQualifiedName>|string $asset */
    public function __invoke(AbstractAsset|string $asset): bool
    {
        if (!$this->enabled) {
            return true;
        }

        if ($asset instanceof AbstractNamedObject) {
            $asset = $asset->getObjectName()->toString();
        } elseif ($asset instanceof AbstractAsset) {
            $asset = $asset->getName();
        }

        return 0 !== strcasecmp($asset, $this->tableName);
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();

        if ($command instanceof ValidateSchemaCommand || $command instanceof UpdateCommand) {
            $this->enabled = true;
        }
    }
}
