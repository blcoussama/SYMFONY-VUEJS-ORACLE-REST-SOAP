<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\OCI8\Middleware\InitializeSession;

/**
 * Sur Oracle (pilote oci8 ou pdo_oci), fixe les formats NLS de la session (dates
 * « YYYY-MM-DD HH24:MI:SS », séparateur décimal « . ») attendus par Doctrine, via
 * le middleware fourni par DBAL. Sans effet sur PostgreSQL, SQLite ou MySQL.
 */
#[AsMiddleware]
final class OracleSessionMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        $oracleDriver = (new InitializeSession())->wrap($driver);

        return new class($driver, $oracleDriver) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly Driver $oracleDriver)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $name = $params['driver'] ?? '';

                return \in_array($name, ['oci8', 'pdo_oci'], true) ? $this->oracleDriver->connect($params) : parent::connect($params);
            }
        };
    }
}
