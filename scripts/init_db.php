<?php

// Script di inizializzazione del database SQLite
// a partire dallo schema corrente.

declare(strict_types=1);

$config = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/Core/Database.php';

use App\Core\Database;

$dbPath = $config['db_path'];
$fresh = in_array('--fresh', $argv, true);
$freshDeleteFailed = false;

if ($fresh && is_file($dbPath)) {
    $freshDeleteFailed = !@unlink($dbPath);
}

$pdo = Database::getConnection($config);
$pdo->exec('PRAGMA foreign_keys = OFF');
if ($fresh && $freshDeleteFailed) {
    resetDatabase($pdo);
}
$schema = file_get_contents(__DIR__ . '/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "Impossibile leggere scripts/schema.sql\n");
    exit(1);
}

$pdo->exec($schema);
$pdo->exec('PRAGMA foreign_keys = ON');

echo "Database inizializzato: {$dbPath}\n";

if ($fresh && $freshDeleteFailed) {
    echo "Nota: file SQLite in uso, reset eseguito in-place.\n";
}

function resetDatabase(PDO $pdo): void
{
    $tables = [
        'report_keys',
        'reports',
        'appointment_status_history',
        'appointments',
        'doctor_availability',
        'api_tokens',
        'web_sessions',
        'users',
        'patients',
        'doctors',
        'category_visits',
    ];

    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }
}
