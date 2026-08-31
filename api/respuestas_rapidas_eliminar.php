<?php
/**
 * api/respuestas_rapidas_eliminar.php (POST, JSON body, sesión)
 *
 * { id, csrf_token }
 *
 * Borra la respuesta completa junto con todos sus adjuntos (filas por
 * cascada FK, archivos físicos a mano — ver RespuestaRapidaRepository::eliminar()).
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/respuestas/RespuestaRapidaRepository.php';
require_once __DIR__ . '/../core/util/RespuestaAdjunto.php';

$usuarioSesion = Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input'), true) ?? [];

if (!csrfVerificar($body['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token de seguridad inválido o expirado. Recarga la página.']);
    exit;
}

$id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta id.']);
    exit;
}

try {
    $paths = RespuestaRapidaRepository::eliminar($id);

    foreach ($paths as $path) {
        eliminarArchivoAdjuntoRespuesta($path);
    }

    error_log("[respuestas_rapidas_eliminar.php] Respuesta id={$id} eliminada por usuario id={$usuarioSesion['id']}");
    echo json_encode(['ok' => true]);
} catch (RespuestaRapidaNoEncontradaException $e) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[respuestas_rapidas_eliminar.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ocurrió un error al eliminar la respuesta.']);
}
