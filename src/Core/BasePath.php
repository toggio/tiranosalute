<?php

// Funzioni di supporto per rilevare e applicare il base path,
// utili anche nelle installazioni in sottocartella.

declare(strict_types=1);

namespace App\Core;

final class BasePath
{
    public static function detect(): string
    {
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));

        // Consideriamo valido il base path solo quando lo script attivo è il front controller.
        // In alcuni casi (php -S) SCRIPT_NAME può riflettere la risorsa richiesta
        // (es. /src/Foo.php), e in quel caso non va usato per dedurre base path.
        $basename = strtolower(basename($scriptName));
        if (!in_array($basename, ['index.php', 'router.php'], true)) {
            return '';
        }

        $dir = str_replace('\\', '/', dirname($scriptName));
        if ($dir === '/' || $dir === '.') {
            return '';
        }

        return rtrim($dir, '/');
    }

    public static function strip(string $path): string
    {
        $basePath = self::detect();
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        if ($path === '') {
            return '/';
        }

        return $path;
    }

    public static function with(string $path): string
    {
        $basePath = self::detect();
        $path = '/' . ltrim($path, '/');
        return $basePath . $path;
    }
}

