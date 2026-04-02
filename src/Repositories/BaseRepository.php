<?php

// Classe base comune ai repository,
// con accesso alla connessione PDO condivisa.

declare(strict_types=1);

namespace App\Repositories;

abstract class BaseRepository
{
    public function __construct(protected readonly \PDO $pdo)
    {
    }
}
