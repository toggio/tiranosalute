<?php

// Controller dei flussi di autenticazione web e bearer:
// login, logout, profilo utente e cambio password.

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthContext;
use App\Core\BasePath;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\AuthService;
use App\Services\UserManagementService;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly UserManagementService $users,
        private readonly array $config
    ) {
    }

    public function login(Request $request): array
    {
        $payload = $request->allBody();
        $email = (string)($payload['email'] ?? '');
        $password = (string)($payload['password'] ?? '');

        if ($email === '' || $password === '') {
            throw new HttpException('email e password sono obbligatori', 422, 'VALIDATION_ERROR');
        }

        $session = $this->auth->loginWebSession($email, $password);
        $this->setCookie(
            (string)($this->config['auth_cookie_name'] ?? 'ts_auth'),
            (string)$session['session_token'],
            true
        );
        $this->setCookie(
            (string)($this->config['csrf_cookie_name'] ?? 'ts_csrf'),
            (string)$session['csrf_token'],
            false
        );

        return [
            'user' => $session['user'],
        ];
    }

    public function logout(AuthContext $auth, Request $request): array
    {
        $authCookieName = (string)($this->config['auth_cookie_name'] ?? 'ts_auth');
        $sessionToken = (string)$request->getCookie($authCookieName, '');
        $this->auth->logout($auth, $sessionToken !== '' ? $sessionToken : null);
        $this->clearCookie($authCookieName, true);
        $this->clearCookie((string)($this->config['csrf_cookie_name'] ?? 'ts_csrf'), false);

        return ['message' => 'Logout effettuato'];
    }

    public function tokenLogin(Request $request): array
    {
        $payload = $request->allBody();
        $email = (string)($payload['email'] ?? '');
        $password = (string)($payload['password'] ?? '');
        $name = (string)($payload['token_name'] ?? 'integration');

        if ($email === '' || $password === '') {
            throw new HttpException('email e password sono obbligatori', 422, 'VALIDATION_ERROR');
        }

        return $this->auth->loginBearer($email, $password, $name);
    }

    public function me(AuthContext $auth): array
    {
        return $auth->user;
    }

    public function changePassword(AuthContext $auth, Request $request): array
    {
        $payload = $request->allBody();
        $current = (string)($payload['current_password'] ?? '');
        $new = (string)($payload['new_password'] ?? '');
        $this->users->changePassword($auth, $current, $new);

        return ['message' => 'Password aggiornata'];
    }

    private function setCookie(string $name, string $value, bool $httpOnly): void
    {
        setcookie($name, $value, [
            'expires' => 0,
            'path' => BasePath::with('/'),
            'secure' => $this->isSecureRequest(),
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ]);
    }

    private function clearCookie(string $name, bool $httpOnly): void
    {
        setcookie($name, '', [
            'expires' => time() - 3600,
            'path' => BasePath::with('/'),
            'secure' => $this->isSecureRequest(),
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ]);
    }

    private function isSecureRequest(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string)$https) !== 'off') {
            return true;
        }

        return (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    }
}
