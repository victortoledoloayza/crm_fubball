<?php
/**
 * api/webhooks/shopify_pedido.php (POST)
 *
 * Recibe el webhook orders/create de Shopify. Autenticación: firma HMAC
 * (header X-Shopify-Hmac-Sha256), no sesión ni token de API — así es como
 * Shopify firma sus webhooks.
 *
 * Idempotente: Shopify reintenta webhooks que no respondan 200 rápido, y
 * es normal recibir el mismo pedido 2+ veces. Si codigo_orden ya existe
 * (PedidoDuplicadoException), respondemos 200 igual — ya lo procesamos
 * antes, no es un error desde la perspectiva de Shopify.
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/pedidos/PedidoRepository.php';

header('Content-Type: application/json; charset=utf-8');

// El HMAC se calcula sobre el body CRUDO — nunca sobre el resultado de
// json_decode()+re-encode, que puede no ser byte-a-byte idéntico.
$rawBody = file_get_contents('php://input');
$hmacHeader = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';
$secreto = env('SHOPIFY_WEBHOOK_SECRET', '');

if ($secreto === '' || $hmacHeader === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Firma inválida.']);
    exit;
}

$calculado = base64_encode(hash_hmac('sha256', $rawBody, $secreto, true));

if (!hash_equals($calculado, $hmacHeader)) {
    error_log('[shopify_pedido.php] Firma HMAC no coincide — request rechazado.');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Firma inválida.']);
    exit;
}

$payload = json_decode($rawBody, true);

if (
    !is_array($payload)
    || empty($payload['line_items'])
    || !is_array($payload['line_items'])
    || !isset($payload['total_price'])
    || !isset($payload['order_number'])
) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Payload incompleto o mal formado.']);
    exit;
}

$pdo = Database::getConnection();

$stmtCanal = $pdo->prepare("SELECT id FROM canales WHERE codigo = 'SHOPIFY' LIMIT 1");
$stmtCanal->execute();
$canalId = $stmtCanal->fetchColumn();

if (!$canalId) {
    error_log('[shopify_pedido.php] No existe el canal SHOPIFY en la tabla canales.');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Configuración del servidor incompleta.']);
    exit;
}

// Idempotencia: mismo order_number → mismo codigo_orden → el segundo
// intento choca contra el UNIQUE y se resuelve como duplicado más abajo.
$codigoOrden = 'SHO-' . $payload['order_number'];

$customer = $payload['customer'] ?? [];
$shipping = $payload['shipping_address'] ?? [];

$nombreCliente = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
if ($nombreCliente === '') {
    $nombreCliente = trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? ''));
}
if ($nombreCliente === '') {
    $nombreCliente = 'Cliente Shopify';
}

$telefono = $customer['phone'] ?? $shipping['phone'] ?? $payload['phone'] ?? null;
$email = $payload['email'] ?? $customer['email'] ?? null;

$direccion = implode(', ', array_filter([
    $shipping['address1'] ?? null,
    $shipping['address2'] ?? null,
    $shipping['city'] ?? null,
])) ?: null;

$items = [];
foreach ($payload['line_items'] as $lineItem) {
    $items[] = [
        'producto_nombre' => $lineItem['title'] ?? ($lineItem['name'] ?? 'Producto sin nombre'),
        'variante'        => $lineItem['variant_title'] ?? '',
        'sku'              => $lineItem['sku'] ?? '',
        'cantidad'         => max(1, (int) ($lineItem['quantity'] ?? 1)),
        'precio_unitario'  => (float) ($lineItem['price'] ?? 0),
    ];
}

// Shopify no manda una fecha límite de despacho — se define un default de
// 48h desde la recepción del webhook. ES UNA DECISIÓN DE NEGOCIO QUE
// PUEDE NECESITAR AJUSTE (ej. según el método de envío elegido en
// checkout, o distinto por canal/temporada) — no hay un criterio real de
// Fubball para esto todavía.
$fechaLimite = (new DateTimeImmutable('+48 hours'))->format('Y-m-d H:i:s');

try {
    $pedidoId = PedidoRepository::crear([
        'codigo_orden'             => $codigoOrden,
        'canal_id'                 => $canalId,
        'cliente_nombre'           => $nombreCliente,
        'cliente_dni'              => null, // Shopify no trae DNI en el payload estándar
        'cliente_telefono'         => $telefono,
        'cliente_email'            => $email,
        'cliente_direccion'        => $direccion,
        'monto_total'              => (float) $payload['total_price'],
        'fecha_limite'             => $fechaLimite,
        'metodo_despacho_id'       => null, // se completa en Verificación, ver PedidoRepository::avanzarFase()
        'requiere_verificar_pago'  => false,
        'origen'                   => 'shopify_webhook',
        'usuario_creador_id'       => null, // no hay usuario logueado detrás de un webhook
        'items'                    => $items,
    ]);

    echo json_encode(['ok' => true, 'pedido_id' => $pedidoId]);
} catch (PedidoDuplicadoException $e) {
    error_log('[shopify_pedido.php] Pedido duplicado (reintento de Shopify), codigo_orden=' . $codigoOrden . ': ' . $e->getMessage());
    echo json_encode(['ok' => true, 'duplicado' => true]);
} catch (Throwable $e) {
    error_log('[shopify_pedido.php] Error al crear pedido: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al procesar el pedido.']);
}
