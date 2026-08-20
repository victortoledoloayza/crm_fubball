<?php
/**
 * core/auth/ApiToken.php
 *
 * Autenticación máquina-a-máquina (webhooks, integraciones) vía token
 * fijo en el header Authorization — el equivalente de Auth::requireLogin()
 * pero sin sesión ni CSRF (no hay navegador de por medio). Los tokens se
 * generan con scripts/crear_api_token.php y solo se guarda su hash en
 * `api_tokens` (ver CREATE TABLE en el README) — el valor en texto plano
 * nunca se persiste.
 */

// Algunos SAPI/proxies no exponen Authorization en getallheaders() (p.ej.
// PHP-FPM sin CGIPassAuth en algunos hostings compartidos) — se prueba
// también $_SERVER como respaldo, importante porque este proyecto está
// pensado para correr en cPanel.
function obtenerHeaderAuthorization(): ?string
{
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $clave => $valor) {
            if (strtolower($clave) === 'authorization') {
                return $valor;
            }
        }
    }

    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    return null;
}

// Corta la ejecución con 401 JSON si el header Authorization: Bearer
// {token} no trae un token válido y activo. Si es válido, actualiza
// ultimo_uso y devuelve la fila completa de api_tokens.
function requireApiToken(): array
{
    header('Content-Type: application/json; charset=utf-8');

    $encabezado = obtenerHeaderAuthorization();

    if ($encabezado === null || !preg_match('/^Bearer\s+(.+)$/i', trim($encabezado), $coincidencias)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Token inválido o ausente']);
        exit;
    }

    $token = trim($coincidencias[1]);
    $hash = hash('sha256', $token);

    $pdo = Database::getConnection();

    $stmt = $pdo->prepare('SELECT * FROM api_tokens WHERE token_hash = ? AND activo = 1 LIMIT 1');
    $stmt->execute([$hash]);
    $fila = $stmt->fetch();

    if (!$fila) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Token inválido o ausente']);
        exit;
    }

    $stmtActualizar = $pdo->prepare('UPDATE api_tokens SET ultimo_uso = NOW() WHERE id = ?');
    $stmtActualizar->execute([$fila['id']]);

    return $fila;
}
