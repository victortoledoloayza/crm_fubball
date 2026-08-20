<?php
/**
 * api/pedidos_avanzar.php (POST, JSON body)
 *
 * { pedido_id, nuevo_estado, campo_responsable, responsable_id, csrf_token }
 *
 * Avanza un pedido a la siguiente fase del Tablero KDS, asignando el
 * responsable de esa fase. El usuario que ejecuta la acción es el de la
 * sesión — puede ser distinto del responsable que está asignando.
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/pedidos/PedidoRepository.php';

$usuarioSesion = Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// Mismo mecanismo de core/auth/csrf.php que usuarios_guardar.php, pero
// el token viaja en el JSON en vez de $_POST porque el body es JSON.
if (!csrfVerificar($body['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token de seguridad inválido o expirado. Recarga la página.']);
    exit;
}

$pedidoId = filter_var($body['pedido_id'] ?? null, FILTER_VALIDATE_INT);
$nuevoEstado = (string) ($body['nuevo_estado'] ?? '');
$campoResponsable = (string) ($body['campo_responsable'] ?? '');
$responsableId = filter_var($body['responsable_id'] ?? null, FILTER_VALIDATE_INT);

// Solo aplica en verificación → despacho, y solo si el pedido todavía no
// tenía método asignado (pedidos de Shopify/extensión Chrome) — ver
// PedidoRepository::avanzarFase().
$metodoDespachoId = null;
if (!empty($body['metodo_despacho_id'])) {
    $valor = filter_var($body['metodo_despacho_id'], FILTER_VALIDATE_INT);
    $metodoDespachoId = $valor !== false ? $valor : null;
}

if (!$pedidoId || !$nuevoEstado || !$campoResponsable || !$responsableId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Faltan datos para avanzar el pedido.']);
    exit;
}

try {
    PedidoRepository::avanzarFase($pedidoId, $nuevoEstado, $campoResponsable, $responsableId, (int) $usuarioSesion['id'], $metodoDespachoId);
    echo json_encode(['ok' => true]);
} catch (PedidoTransicionInvalidaException $e) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[pedidos_avanzar.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ocurrió un error al avanzar el pedido.']);
}
