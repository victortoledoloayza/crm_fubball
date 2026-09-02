<?php
/**
 * api/pedidos_retroceder.php (POST, JSON body)
 *
 * { pedido_id, estado_actual, csrf_token }
 *
 * Regresa un pedido a la fase anterior — Tablero KDS, Cola de Despacho o
 * Cola de Facturación — para corregir un error operativo (avance por
 * accidente, o hay que reabrir el paso previo). El usuario que ejecuta la
 * acción es el de la sesión.
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/pedidos/PedidoRepository.php';

$usuarioSesion = Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// Mismo mecanismo de core/auth/csrf.php que pedidos_avanzar.php.
if (!csrfVerificar($body['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token de seguridad inválido o expirado. Recarga la página.']);
    exit;
}

$pedidoId = filter_var($body['pedido_id'] ?? null, FILTER_VALIDATE_INT);
$estadoActual = (string) ($body['estado_actual'] ?? '');

if (!$pedidoId || !$estadoActual) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Faltan datos para regresar el pedido.']);
    exit;
}

try {
    PedidoRepository::retrocederFase($pedidoId, $estadoActual, (int) $usuarioSesion['id']);
    echo json_encode(['ok' => true]);
} catch (PedidoTransicionInvalidaException $e) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[pedidos_retroceder.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ocurrió un error al regresar el pedido.']);
}
