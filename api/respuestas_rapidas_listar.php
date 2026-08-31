<?php
/**
 * api/respuestas_rapidas_listar.php (GET, sesión)
 *
 * Lista todas las respuestas rápidas (activas e inactivas — a diferencia
 * del endpoint de token, este alimenta la tabla de gestión del panel
 * admin, ver respuestas_rapidas.php) con sus adjuntos.
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/respuestas/RespuestaRapidaRepository.php';

Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$respuestas = RespuestaRapidaRepository::listar();

foreach ($respuestas as &$respuesta) {
    foreach ($respuesta['adjuntos'] as &$adjunto) {
        $adjunto['url'] = baseUrl($adjunto['path']);
    }
    unset($adjunto);
}
unset($respuesta);

echo json_encode(['ok' => true, 'respuestas' => $respuestas]);
