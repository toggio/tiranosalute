<?php

// Endpoint di metadati usato dalla SPA
// per base path, cookie di sicurezza e nome applicazione.

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BasePath;

final class MetaController
{
    public function __construct(private readonly array $config)
    {
    }

    public function appInfo(): array
    {
        return [
            'app_name' => $this->config['app_name'] ?? 'Tirano Salute',
            'base_path' => BasePath::detect(),
            'api_base' => BasePath::with('/api'),
            'timezone' => $this->config['timezone'] ?? 'UTC',
            'csrf_cookie_name' => $this->config['csrf_cookie_name'] ?? 'ts_csrf',
            'auth_cookie_name' => $this->config['auth_cookie_name'] ?? 'ts_auth',
        ];
    }
}
