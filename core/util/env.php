<?php
/**
 * core/util/env.php
 *
 * Cargador de variables de entorno sin dependencias externas (funciona
 * igual con o sin Composer, pensado para hosting compartido tipo cPanel).
 *
 * Uso:
 *   require_once __DIR__ . '/core/util/env.php';
 *   loadEnv(__DIR__ . '/.env');
 *   $dbHost = env('DB_HOST');
 */

function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        throw new RuntimeException(
            ".env no encontrado en {$path} — copia .env.example a .env y completa los valores."
        );
    }

    $lineas = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lineas as $linea) {
        $linea = trim($linea);

        if ($linea === '' || strpos($linea, '#') === 0) {
            continue;
        }

        if (strpos($linea, '=') === false) {
            continue;
        }

        [$clave, $valor] = explode('=', $linea, 2);
        $clave = trim($clave);
        $valor = trim(trim($valor), "\"'");

        if (getenv($clave) === false) {
            putenv("{$clave}={$valor}");
            $_ENV[$clave] = $valor;
        }
    }
}

function env(string $clave, $default = null)
{
    $valor = getenv($clave);

    return $valor !== false ? $valor : $default;
}
