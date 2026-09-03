<?php
/**
 * api/pedido_generar_etiqueta.php (POST, JSON body)
 *
 * { pedido_id, csrf_token }
 *
 * Genera la etiqueta de despacho propia (4x6") para pedidos que no llegan
 * con etiqueta ya hecha del marketplace — ver core/pedidos/EtiquetaGenerator.php.
 * Guarda el PDF en uploads/etiquetas/{codigo_orden}.pdf (misma carpeta y
 * convención que pedido_subir_etiqueta.php) y actualiza
 * pedidos.etiqueta_pdf_url, así el resto del flujo (link "#codigo_orden",
 * botón "🖨️ Etiqueta") funciona igual sin importar el origen de la etiqueta.
 *
 * Elegibilidad (mismo criterio que pinta el botón en pedidos.php): canal
 * TSI/SHOPIFY/WHATSAPP, o cualquier pedido de alta manual (origen='manual')
 * — Falabella/Ripley/Intercorp traen su propia etiqueta y suben el PDF a
 * mano, salvo que el pedido sea de alta manual igual.
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/pedidos/PedidoRepository.php';
require_once __DIR__ . '/../core/pedidos/EtiquetaGenerator.php';

const CANALES_ETIQUETA_AUTO = ['TSI', 'SHOPIFY', 'WHATSAPP'];

$usuarioSesion = Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// Mismo mecanismo de core/auth/csrf.php que pedidos_avanzar.php — el token
// viaja en el JSON en vez de $_POST porque el body es JSON.
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

$pedido = PedidoRepository::obtener($pedidoId);
if ($pedido === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Pedido no encontrado.']);
    exit;
}

$elegible = in_array($pedido['canal_codigo'], CANALES_ETIQUETA_AUTO, true) || $pedido['origen'] === 'manual';
if (!$elegible) {
    http_response_code(409);
    echo json_encode([
        'ok' => false,
        'error' => 'Este canal trae su propia etiqueta del marketplace — subila con "Subir etiqueta PDF".',
    ]);
    exit;
}

try {
    EtiquetaGenerator::generarParaPedido($pedido);

    $urlPublica = baseUrl('uploads/etiquetas/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $pedido['codigo_orden']) . '.pdf');
    PedidoRepository::actualizarEtiquetaPdf($pedidoId, $urlPublica);

    error_log("[pedido_generar_etiqueta.php] Etiqueta generada para pedido id={$pedidoId} por usuario id={$usuarioSesion['id']}");

    echo json_encode(['ok' => true, 'etiqueta_pdf_url' => $urlPublica]);
} catch (Throwable $e) {
    error_log('[pedido_generar_etiqueta.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ocurrió un error al generar la etiqueta.']);
}
