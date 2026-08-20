<?php
/**
 * api/despacho_coordinar_motorizado.php (POST, JSON body)
 *
 * { pedido_id, csrf_token }
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/pedidos/PedidoRepository.php';

$usuarioSesion = Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input'), true) ?? [];

if (!csrfVerificar($body['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token de seguridad inválido o expirado. Recarga la página.']);
    exit;
}

$pedidoId = filter_var($body['pedido_id'] ?? null, FILTER_VALIDATE_INT);

if (!$pedidoId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta pedido_id.']);
    exit;
}

try {
    PedidoRepository::coordinarMotorizado($pedidoId, (int) $usuarioSesion['id']);
    echo json_encode(['ok' => true]);
} catch (PedidoTransicionInvalidaException $e) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[despacho_coordinar_motorizado.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ocurrió un error al coordinar el motorizado.']);
}
