<?php
/**
 * api/pedidos_prioridad.php (POST, JSON body)
 *
 * { pedido_id, prioridad, csrf_token }
 *
 * Sobreescribe la prioridad de un pedido a mano desde el tablero — marca
 * prioridad_manual=1, así que a partir de ahí
 * PedidoRepository::recalcularPrioridadesAutomaticas() (el refresco de
 * 30s) ya no la vuelve a tocar. Mismo nivel de permiso que avanzar/
 * retroceder fase — cualquier usuario logueado, no es una acción
 * restringida a admin (a diferencia de editar/eliminar pedidos).
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/pedidos/PedidoRepository.php';

Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input'), true) ?? [];

if (!csrfVerificar($body['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token de seguridad inválido o expirado. Recarga la página.']);
    exit;
}

$pedidoId = filter_var($body['pedido_id'] ?? null, FILTER_VALIDATE_INT);
$prioridad = (string) ($body['prioridad'] ?? '');

if (!$pedidoId || !in_array($prioridad, ['normal', 'urgente', 'muy_urgente'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Faltan datos o la prioridad no es válida.']);
    exit;
}

try {
    PedidoRepository::actualizarPrioridadManual($pedidoId, $prioridad);
    echo json_encode(['ok' => true]);
} catch (PedidoTransicionInvalidaException $e) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[pedidos_prioridad.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ocurrió un error al cambiar la prioridad.']);
}
