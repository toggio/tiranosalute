<?php

// Contesto autenticato associato alla richiesta corrente:
// profilo utente, metodo di accesso ed eventuale token.

declare(strict_types=1);

namespace App\Core;

final class AuthContext
{
    public function __construct(
        public readonly array $user,
        public readonly string $method,
        public readonly ?array $token = null
    ) {
    }

    public function id(): int
    {
        return (int)$this->user['id'];
    }

    public function role(): string
    {
        return (string)$this->user['role'];
    }

    public function is(string $role): bool
    {
        return $this->role() === $role;
    }

    public function in(array $roles): bool
    {
        return in_array($this->role(), $roles, true);
    }
}
