<?php

// Apertura della connessione SQLite condivisa
// e attivazione dei vincoli richiesti dallo schema applicativo.

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Database
{
    private static ?\PDO $pdo = null;

    public static function getConnection(array $config): \PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dbPath = $config['db_path'] ?? null;
        if (!$dbPath) {
            throw new RuntimeException('Database path is not configured');
        }

        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        self::$pdo = new \PDO('sqlite:' . $dbPath);
        self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        self::$pdo->exec('PRAGMA foreign_keys = ON');

        return self::$pdo;
    }
}
