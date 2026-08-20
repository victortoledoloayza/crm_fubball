<?php
/**
 * core/bootstrap.php
 *
 * Punto de entrada común del proyecto: carga el .env, configura errores,
 * zona horaria y sesión, y deja disponibles Database, Auth y las
 * funciones de CSRF. Toda página del proyecto debe empezar con:
 *
 *   require_once __DIR__ . '/core/bootstrap.php';
 */

require_once __DIR__ . '/util/env.php';

loadEnv(__DIR__ . '/../.env');

$appEnv = env('APP_ENV', 'production');

// En production nunca se muestran errores PHP crudos al usuario final;
// siguen quedando registrados en el log del servidor.
if ($appEnv === 'local') {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}
error_reporting(E_ALL);

date_default_timezone_set('America/Lima');

require_once __DIR__ . '/db/Database.php';
require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/auth/csrf.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(env('SESSION_NAME', 'fubball_kds_session'));
    session_set_cookie_params([
        'lifetime' => (int) env('SESSION_LIFETIME_MIN', 480) * 60,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// URL base del proyecto (sin slash final), para construir redirecciones que
// funcionen igual si el proyecto vive en la raíz del dominio o en una
// subcarpeta (típico en hosting compartido tipo cPanel).
function baseUrl(string $ruta = ''): string
{
    $base = rtrim(env('APP_URL', ''), '/');

    return $base . '/' . ltrim($ruta, '/');
}
