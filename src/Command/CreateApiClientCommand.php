<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\BusinessRuleException;
use App\Service\ApiClientManager;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crée une clé d'API partenaire depuis la ligne de commande (utile en Docker, sans interface).
 */
#[AsCommand(name: 'app:api-client:create', description: 'Crée un partenaire API et affiche sa clé (une seule fois).')]
final class CreateApiClientCommand
{
    public function __construct(private readonly ApiClientManager $manager)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Nom du partenaire')] string $name): int
    {
        try {
            $created = $this->manager->create($name);
        } catch (BusinessRuleException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Partenaire « %s » créé.', $created['client']->getName()));
        $io->writeln('Clé d’API (affichée une seule fois, à transmettre par un canal sûr) :');
        $io->writeln($created['key']);

        return Command::SUCCESS;
    }
}
