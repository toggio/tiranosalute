<?php

// Servizio che tiene insieme sessioni web e bearer token.
// Qui passano verifica credenziali, profilo autenticato e controlli CSRF.

declare(strict_types=1);

namespace App\Services;

use App\Core\AuthContext;
use App\Core\HttpException;
use App\Repositories\TokenRepository;
use App\Repositories\UserRepository;
use App\Repositories\WebSessionRepository;
use App\Support\PublicApi;

final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TokenRepository $tokens,
        private readonly WebSessionRepository $webSessions,
        private readonly array $config
    ) {
    }

    public function loginWebSession(string $email, string $password): array
    {
        $user = $this->users->findByEmail($email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new HttpException('Credenziali non valide', 401, 'UNAUTHORIZED');
        }

        if ((int)$user['active'] !== 1) {
            throw new HttpException('Utente non attivo', 403, 'FORBIDDEN');
        }

        $rawSessionToken = bin2hex(random_bytes(32));
        $sessionHash = hash('sha256', $rawSessionToken);
        $csrfToken = bin2hex(random_bytes(32));
        $ttlHours = (int)($this->config['web_session_ttl_hours'] ?? 12);
        $expiresAt = date('Y-m-d H:i:s', time() + ($ttlHours * 3600));
        $this->webSessions->create((int)$user['id'], $sessionHash, $csrfToken, $expiresAt);

        return [
            'user' => $this->sanitizeUser($user),
            'session_token' => $rawSessionToken,
            'csrf_token' => $csrfToken,
            'expires_at' => $expiresAt,
        ];
    }

    public function logoutWebSession(?string $sessionToken): void
    {
        $sessionToken = trim((string)$sessionToken);
        if ($sessionToken === '') {
            return;
        }

        $this->webSessions->revokeByHash(hash('sha256', $sessionToken));
    }

    public function logout(AuthContext $auth, ?string $sessionToken = null): void
    {
        if ($sessionToken !== null && trim($sessionToken) !== '') {
            $this->logoutWebSession($sessionToken);
        }

        if ($auth->method === 'bearer' && !empty($auth->token['id'])) {
            $this->tokens->revokeById((int)$auth->token['id']);
        }
    }

    public function loginBearer(string $email, string $password, string $name = 'integration'): array
    {
        $user = $this->users->findByEmail($email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new HttpException('Credenziali non valide', 401, 'UNAUTHORIZED');
        }

        if ((int)$user['active'] !== 1) {
            throw new HttpException('Utente non attivo', 403, 'FORBIDDEN');
        }

        $rawToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawToken);
        $ttlHours = (int)($this->config['token_ttl_hours'] ?? 24);
        $expiresAt = date('Y-m-d H:i:s', time() + ($ttlHours * 3600));
        $this->tokens->create((int)$user['id'], $hash, $name, $expiresAt);

        return [
            'access_token' => $rawToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt,
            'user' => $this->sanitizeUser($user),
        ];
    }

    public function authenticate(?string $authorizationHeader = null, ?string $webSessionToken = null): ?AuthContext
    {
        $bearer = $this->extractBearer($authorizationHeader);
        if ($bearer !== null) {
            $result = $this->tokens->findValidByHash(hash('sha256', $bearer));
            if (!$result) {
                throw new HttpException('Bearer token non valido o scaduto', 401, 'UNAUTHORIZED');
            }
            $this->tokens->touchLastUsed((int)$result['token']['id']);
            return new AuthContext($this->sanitizeUser($result['user']), 'bearer', $result['token']);
        }

        $webSessionToken = trim((string)$webSessionToken);
        if ($webSessionToken === '') {
            return null;
        }

        $result = $this->webSessions->findValidByHash(hash('sha256', $webSessionToken));
        if (!$result) {
            return null;
        }

        if ((int)$result['user']['active'] !== 1) {
            return null;
        }

        $this->webSessions->touchLastUsed((int)$result['session']['id']);

        return new AuthContext(
            $this->sanitizeUser($result['user']),
            'cookie',
            [
                'id' => (int)$result['session']['id'],
                'csrf_token' => (string)$result['session']['csrf_token'],
            ]
        );
    }

    public function requireAuth(?AuthContext $context): AuthContext
    {
        if ($context === null) {
            throw new HttpException('Autenticazione richiesta', 401, 'UNAUTHORIZED');
        }
        return $context;
    }

    public function requireRole(AuthContext $context, array $roles): void
    {
        if (!$context->in($roles)) {
            throw new HttpException('Permessi insufficienti', 403, 'FORBIDDEN');
        }
    }

    public function ensureCsrfHeaderValid(AuthContext $context, string $headerToken, string $cookieToken): void
    {
        $expected = (string)($context->token['csrf_token'] ?? '');
        $headerToken = trim($headerToken);
        $cookieToken = trim($cookieToken);

        if (
            $expected === ''
            || $headerToken === ''
            || $cookieToken === ''
            || !hash_equals($cookieToken, $headerToken)
            || !hash_equals($expected, $headerToken)
        ) {
            throw new HttpException('CSRF token mancante o non valido', 403, 'CSRF_INVALID');
        }
    }

    public function sanitizeUser(array $user): array
    {
        $publicUser = PublicApi::user($user);
        $publicUser['must_change_password'] = !empty($user['must_change_password']);

        return $publicUser;
    }

    private function extractBearer(?string $authorizationHeader): ?string
    {
        if (!$authorizationHeader) {
            return null;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($authorizationHeader), $m)) {
            return null;
        }

        return trim($m[1]);
    }
}
