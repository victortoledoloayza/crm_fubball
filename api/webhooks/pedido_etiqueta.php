<?php
/**
 * api/webhooks/pedido_etiqueta.php (POST, multipart/form-data)
 *
 * Endpoint hermano de api/pedido_subir_etiqueta.php, pero para la
 * extensión de Chrome en vez de un humano en el navegador: la extensión
 * corre sobre sellercenter.ripleylabs.com (side panel), no tiene sesión
 * de fubball-kds, así que se autentica con el mismo token Bearer que
 * api/webhooks/extension_pedido.php (ver core/auth/ApiToken.php) — sin
 * sesión, sin CSRF.
 *
 * Recibe:
 *   codigo_orden (texto, obligatorio) — para encontrar el pedido, ya
 *     debe existir (este endpoint solo adjunta etiqueta, no crea pedidos).
 *   etiqueta (archivo, obligatorio) — el PDF.
 *
 * Guarda en uploads/etiquetas/{codigo_orden}.pdf, reemplazando la
 * anterior si ya existía — misma convención y misma validación de PDF
 * real (mime type) que el endpoint de sesión (ver core/util/EtiquetaPdf.php).
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/ApiToken.php';
require_once __DIR__ . '/../../core/pedidos/PedidoRepository.php';
require_once __DIR__ . '/../../core/util/EtiquetaPdf.php';

$tokenFila = requireApiToken();

header('Content-Type: application/json; charset=utf-8');

$codigoOrden = trim((string) ($_POST['codigo_orden'] ?? ''));
if ($codigoOrden === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta codigo_orden.']);
    exit;
}

$pedido = PedidoRepository::obtenerPorCodigoOrden($codigoOrden);
if ($pedido === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'No existe ningún pedido con ese código de orden. Créalo primero desde el Capturador.']);
    exit;
}

if (empty($_FILES['etiqueta']) || $_FILES['etiqueta']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No se recibió el archivo o hubo un error al subirlo.']);
    exit;
}

$archivo = $_FILES['etiqueta'];

$errorValidacion = validarArchivoEtiquetaPdf($archivo);
if ($errorValidacion !== null) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $errorValidacion]);
    exit;
}

try {
    $urlPublica = guardarEtiquetaPdf($archivo, $pedido['codigo_orden']);
} catch (RuntimeException $e) {
    error_log("[pedido_etiqueta.php] {$e->getMessage()} (pedido id={$pedido['id']})");
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor.']);
    exit;
}

PedidoRepository::actualizarEtiquetaPdf((int) $pedido['id'], $urlPublica);

error_log("[pedido_etiqueta.php] Etiqueta subida para pedido id={$pedido['id']} (codigo_orden={$codigoOrden}) vía token id={$tokenFila['id']}");

echo json_encode(['ok' => true, 'etiqueta_pdf_url' => $urlPublica]);
