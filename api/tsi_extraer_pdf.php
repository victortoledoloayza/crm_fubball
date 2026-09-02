<?php
/**
 * api/tsi_extraer_pdf.php (POST, multipart/form-data)
 *
 * Extrae los campos de una Orden de Pedido en PDF de TSI para pre-llenar
 * el formulario de alta manual (pedidos_nuevo.php) — ver TsiPdfParser.
 * NO crea el pedido ni guarda el archivo: solo lee el PDF subido, lo
 * parsea en memoria (desde tmp_name) y devuelve los campos encontrados
 * para que el usuario los revise antes de confirmar con el botón "Crear
 * pedido" que ya existe.
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/pedidos/TsiPdfParser.php';

// Mismo límite que pedido_subir_etiqueta.php.
const MAX_BYTES_ORDEN_TSI = 15 * 1024 * 1024;

$usuarioSesion = Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

csrfRequerir();

if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No se recibió el archivo o hubo un error al subirlo.']);
    exit;
}

$archivo = $_FILES['archivo'];

if ($archivo['size'] <= 0 || $archivo['size'] > MAX_BYTES_ORDEN_TSI) {
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

$resultado = TsiPdfParser::parseArchivo($archivo['tmp_name']);

if (!$resultado['reconocido']) {
    // No es un error de servidor (200 igual) — el archivo se leyó bien,
    // solo no matchea el formato esperado. El frontend deja el formulario
    // intacto para que se complete a mano.
    echo json_encode(['ok' => false, 'error' => $resultado['error']]);
    exit;
}

error_log(
    "[tsi_extraer_pdf.php] PDF de orden TSI parseado por usuario id={$usuarioSesion['id']}"
    . ' — codigo_orden=' . ($resultado['campos']['codigo_orden'] ?? 'null')
);

echo json_encode([
    'ok' => true,
    'campos' => $resultado['campos'],
    'campos_faltantes' => $resultado['campos_faltantes'],
]);
