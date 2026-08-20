<?php
/**
 * api/pedido_subir_etiqueta.php (POST, multipart/form-data)
 *
 * Sube la etiqueta PDF de un pedido y la guarda en
 * uploads/etiquetas/{codigo_orden}.pdf, reemplazando la anterior si ya
 * existía. Acción humana normal — va por sesión + CSRF, no por token de
 * API.
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/pedidos/PedidoRepository.php';

const MAX_BYTES_ETIQUETA = 15 * 1024 * 1024; // 15MB

$usuarioSesion = Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

csrfRequerir();

$pedidoId = filter_input(INPUT_POST, 'pedido_id', FILTER_VALIDATE_INT);
if (!$pedidoId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta pedido_id.']);
    exit;
}

$pedido = PedidoRepository::obtener($pedidoId);
if ($pedido === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Pedido no encontrado.']);
    exit;
}

if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No se recibió el archivo o hubo un error al subirlo.']);
    exit;
}

$archivo = $_FILES['archivo'];

if ($archivo['size'] <= 0 || $archivo['size'] > MAX_BYTES_ETIQUETA) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'El archivo debe ser un PDF de hasta 15MB.']);
    exit;
}

$extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
if ($extension !== 'pdf') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'El archivo debe tener extensión .pdf.']);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeReal = finfo_file($finfo, $archivo['tmp_name']);
finfo_close($finfo);

if ($mimeReal !== 'application/pdf') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'El archivo no es un PDF válido.']);
    exit;
}

$carpetaEtiquetas = __DIR__ . '/../uploads/etiquetas';
if (!is_dir($carpetaEtiquetas) && !mkdir($carpetaEtiquetas, 0755, true) && !is_dir($carpetaEtiquetas)) {
    error_log("[pedido_subir_etiqueta.php] No se pudo crear {$carpetaEtiquetas}");
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor.']);
    exit;
}

// codigo_orden puede venir de una integración externa (Shopify, extensión
// Chrome) — se sanea antes de usarlo como nombre de archivo para
// descartar cualquier intento de path traversal.
$nombreArchivo = preg_replace('/[^A-Za-z0-9_-]/', '_', $pedido['codigo_orden']) . '.pdf';
$rutaDestino = $carpetaEtiquetas . '/' . $nombreArchivo;

if (!move_uploaded_file($archivo['tmp_name'], $rutaDestino)) {
    error_log("[pedido_subir_etiqueta.php] move_uploaded_file falló para pedido id={$pedidoId}");
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor.']);
    exit;
}

$urlPublica = baseUrl('uploads/etiquetas/' . $nombreArchivo);
PedidoRepository::actualizarEtiquetaPdf($pedidoId, $urlPublica);

error_log("[pedido_subir_etiqueta.php] Etiqueta subida para pedido id={$pedidoId} por usuario id={$usuarioSesion['id']}");

echo json_encode(['ok' => true, 'etiqueta_pdf_url' => $urlPublica]);
