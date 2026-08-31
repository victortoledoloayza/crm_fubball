<?php
/**
 * api/respuestas_rapidas_adjunto_eliminar.php (POST, JSON body, sesión)
 *
 * { adjunto_id, csrf_token }
 *
 * Borra un único adjunto sin tocar el resto de la respuesta — para el
 * caso de "me equivoqué de archivo" sin tener que recrear la respuesta
 * completa.
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

$adjuntoId = filter_var($body['adjunto_id'] ?? null, FILTER_VALIDATE_INT);
if (!$adjuntoId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta adjunto_id.']);
    exit;
}

try {
    $path = RespuestaRapidaRepository::eliminarAdjunto($adjuntoId);

    if ($path === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'El adjunto no existe.']);
        exit;
    }

    eliminarArchivoAdjuntoRespuesta($path);

    error_log("[respuestas_rapidas_adjunto_eliminar.php] Adjunto id={$adjuntoId} eliminado por usuario id={$usuarioSesion['id']}");
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[respuestas_rapidas_adjunto_eliminar.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ocurrió un error al eliminar el adjunto.']);
}
