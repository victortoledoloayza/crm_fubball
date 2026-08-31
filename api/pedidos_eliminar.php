<?php
/**
 * api/pedidos_eliminar.php (POST, JSON body)
 *
 * { pedido_id, csrf_token }
 *
 * Elimina un pedido completo (items y eventos se van en cascada, ver
 * PedidoRepository::eliminar()) y su etiqueta PDF si tenía una subida.
 * Acción destructiva e irreversible — restringida a rol 'admin'.
 *
 * A diferencia de Auth::requireRole(), que corta con un 403 en texto
 * plano (pensado para páginas completas como usuarios.php), acá se
 * valida el rol a mano y se responde siempre en JSON, para que el
 * fetch() del Tablero KDS pueda leer data.error normalmente. El chequeo
 * de rol va ANTES que cualquier otra cosa (antes de tocar el body o el
 * CSRF) — un no-admin no debe poder ni intentar el borrado armando la
 * request a mano.
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/pedidos/PedidoRepository.php';

$usuarioSesion = Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($usuarioSesion['rol'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Solo un administrador puede eliminar pedidos.']);
    exit;
}

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
    $eliminado = PedidoRepository::eliminar($pedidoId);

    // Best-effort: si el archivo ya no está o falla el unlink, no se
    // aborta nada — el pedido ya se borró de la BD (commit hecho) y un
    // PDF huérfano en uploads/etiquetas/ no es un problema para este caso
    // de uso (datos de prueba).
    if ($eliminado['etiqueta_pdf_url'] !== null) {
        $nombreArchivo = preg_replace('/[^A-Za-z0-9_-]/', '_', $eliminado['codigo_orden']) . '.pdf';
        $rutaArchivo = __DIR__ . '/../uploads/etiquetas/' . $nombreArchivo;
        if (is_file($rutaArchivo) && !@unlink($rutaArchivo)) {
            error_log("[pedidos_eliminar.php] No se pudo borrar {$rutaArchivo} (pedido id={$pedidoId})");
        }
    }

    error_log("[pedidos_eliminar.php] Pedido id={$pedidoId} eliminado por usuario id={$usuarioSesion['id']}");
    echo json_encode(['ok' => true]);
} catch (PedidoTransicionInvalidaException $e) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[pedidos_eliminar.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ocurrió un error al eliminar el pedido.']);
}
