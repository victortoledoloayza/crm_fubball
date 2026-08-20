<?php
/**
 * core/auth/csrf.php
 *
 * Generación y verificación de token CSRF, uno por sesión. Todo formulario
 * que haga cambios (POST) debe incluir csrfCampo() y todo script que lo
 * procese debe llamar csrfRequerir() antes de tocar la base de datos.
 */

function csrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

// Devuelve el <input type="hidden"> listo para incrustar en un <form>.
function csrfCampo(): string
{
    $token = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');

    return "<input type=\"hidden\" name=\"csrf_token\" value=\"{$token}\">";
}

function csrfVerificar(?string $tokenRecibido): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token']) || empty($tokenRecibido)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $tokenRecibido);
}

// Corta la ejecución con 403 si el token del POST actual no es válido.
// Debe llamarse al inicio de cualquier script que procese un formulario.
function csrfRequerir(): void
{
    if (!csrfVerificar($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Token de seguridad inválido o expirado. Recarga la página e intenta de nuevo.');
    }
}
