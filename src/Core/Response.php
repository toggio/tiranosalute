<?php

// Funzioni usate per produrre le risposte JSON standard
// dell'applicazione, sia per i successi sia per gli errori API.

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function json(mixed $data, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(mixed $data, int $statusCode = 200): never
    {
        self::json(['data' => $data], $statusCode);
    }

    public static function message(string $message, int $statusCode = 200, array $extra = []): never
    {
        self::json(array_merge(['message' => $message], $extra), $statusCode);
    }

    public static function error(string $code, string $message, int $statusCode, mixed $details = null): never
    {
        $payload = [
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
        if ($details !== null) {
            $payload['error']['details'] = $details;
        }
        self::json($payload, $statusCode);
    }
}
