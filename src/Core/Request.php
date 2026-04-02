<?php

// Raccolta dei dati della richiesta HTTP:
// metodo, path, query, cookie, header, body e parametri di route.

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private string $method;
    private string $path;
    private array $query;
    private array $cookies;
    private array $headers;
    private ?array $jsonBody = null;
    private array $routeParams = [];

    private function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $parsed = parse_url($uri);
        $rawPath = $parsed['path'] ?? '/';
        $this->path = BasePath::strip($rawPath);
        $this->query = $_GET;
        $this->cookies = $_COOKIE;
        $this->headers = $this->parseHeaders();
    }

    public static function capture(): self
    {
        return new self();
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQueryParam(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function getQuery(): array
    {
        return $this->query;
    }

    public function getCookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    public function getCookies(): array
    {
        return $this->cookies;
    }

    public function getHeader(string $name, mixed $default = null): mixed
    {
        $needle = strtolower($name);
        return $this->headers[$needle] ?? $default;
    }

    public function json(): array
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }

        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            $this->jsonBody = [];
            return $this->jsonBody;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new HttpException('Payload JSON non valido', 400, 'INVALID_JSON');
        }

        $this->jsonBody = $decoded;
        return $this->jsonBody;
    }

    public function allBody(): array
    {
        if (str_contains((string)$this->getHeader('content-type', ''), 'application/json')) {
            return $this->json();
        }
        return $_POST;
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function getRouteParam(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        return $headers;
    }
}
