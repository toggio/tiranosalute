<?php

// Eccezione HTTP applicativa con stato, codice logico
// ed eventuali dettagli da restituire nelle risposte API.

declare(strict_types=1);

namespace App\Core;

use Exception;

final class HttpException extends Exception
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 400,
        private readonly string $codeName = 'BAD_REQUEST',
        private readonly mixed $details = null
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getCodeName(): string
    {
        return $this->codeName;
    }

    public function getDetails(): mixed
    {
        return $this->details;
    }
}
