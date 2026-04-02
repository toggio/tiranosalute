<?php

// Router minimale dell'applicazione:
// associa metodo e path alle route registrate e converte le eccezioni in risposte HTTP.

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $method = strtoupper($method);
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . rtrim($regex, '/') . '/?$#';

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => $regex,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): never
    {
        $path = $request->getPath();
        $method = $request->getMethod();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            $request->setRouteParams($params);

            try {
                $result = ($route['handler'])($request);
                if ($result === null) {
                    Response::json(['message' => 'ok']);
                }
                $statusCode = 200;
                if (is_array($result) && array_key_exists('__status_code', $result)) {
                    $statusCode = (int)$result['__status_code'];
                    unset($result['__status_code']);
                }
                if (is_array($result) && isset($result['__raw_response']) && $result['__raw_response'] === true) {
                    http_response_code($result['status'] ?? 200);
                    if (!empty($result['headers']) && is_array($result['headers'])) {
                        foreach ($result['headers'] as $name => $value) {
                            header($name . ': ' . $value);
                        }
                    }
                    echo (string)($result['body'] ?? '');
                    exit;
                }
                Response::ok($result, $statusCode);
            } catch (HttpException $e) {
                Response::error($e->getCodeName(), $e->getMessage(), $e->getStatusCode(), $e->getDetails());
            } catch (Throwable $e) {
                Response::error('SERVER_ERROR', 'Errore interno del server', 500);
            }
        }

        Response::error('NOT_FOUND', 'Risorsa non trovata', 404);
    }
}
