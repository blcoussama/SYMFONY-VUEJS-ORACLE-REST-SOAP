<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// .env / .env.test sont facultatifs : les valeurs par défaut sont dans config/ (SQLite var/data_test.db).
// DATABASE_URL passée par l'environnement (PostgreSQL, Oracle) est prioritaire.
if (is_file(dirname(__DIR__).'/.env')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
} else {
    $_SERVER['APP_ENV'] ??= 'test';
    $_SERVER['APP_DEBUG'] ??= '1';
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}

/*
 * Base de test recréée une fois par exécution : schéma issu du mapping Doctrine, puis fixtures
 * de démonstration. Chaque test tourne ensuite dans une transaction annulée (DAMADoctrineTestBundle).
 */
(static function (): void {
    if ('1' === ($_SERVER['NETOPS_TEST_KEEP_DB'] ?? getenv('NETOPS_TEST_KEEP_DB'))) {
        return;
    }

    $kernel = new Kernel('test', true);
    $kernel->boot();
    $application = new Application($kernel);
    $application->setAutoExit(false);

    foreach ([
        ['command' => 'doctrine:schema:drop', '--force' => true, '--full-database' => true],
        ['command' => 'doctrine:schema:create'],
        ['command' => 'doctrine:fixtures:load', '--no-interaction' => true],
        // Compteurs de limitation des connexions (login_throttling) remis à zéro.
        ['command' => 'cache:pool:clear', 'pools' => ['cache.rate_limiter']],
    ] as $arguments) {
        $output = new BufferedOutput();
        if (0 !== $application->run(new ArrayInput($arguments + ['--quiet' => true]), $output)) {
            throw new RuntimeException(sprintf("Préparation de la base de test : échec de « %s »\n%s", $arguments['command'], $output->fetch()));
        }
    }

    $kernel->shutdown();
})();
