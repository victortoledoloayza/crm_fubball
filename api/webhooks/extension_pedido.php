<?php
/**
 * api/webhooks/extension_pedido.php (POST, JSON body)
 *
 * Endpoint receptor para la extensión de Chrome (repo aparte — acá solo
 * se recibe y se guarda). Autenticación: token de API
 * (Authorization: Bearer ..., ver core/auth/ApiToken.php), no sesión.
 *
 * Body esperado:
 *   { canal, codigo_orden, cliente_nombre, cliente_dni, cliente_direccion,
 *     fecha_limite, comision, items: [{producto, variante, sku, cantidad,
 *     precio_unitario}] }
 *
 * `comision` (comisión de plataforma del pedido completo) e
 * `items[].precio_unitario` son opcionales — la extensión no siempre los
 * tiene a mano al capturar el pedido en 1 clic. Si vienen, monto_total se
 * calcula en el servidor sumando cantidad*precio_unitario de los items
 * (los que no traen precio cuentan como 0 en la suma).
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/ApiToken.php';
require_once __DIR__ . '/../../core/pedidos/PedidoRepository.php';

$tokenFila = requireApiToken();

header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido.']);
    exit;
}

$canalCodigo = trim((string) ($body['canal'] ?? ''));
$codigoOrden = trim((string) ($body['codigo_orden'] ?? ''));
$clienteNombre = trim((string) ($body['cliente_nombre'] ?? ''));
$clienteDni = trim((string) ($body['cliente_dni'] ?? ''));
$clienteDireccion = trim((string) ($body['cliente_direccion'] ?? ''));
$fechaLimiteRaw = trim((string) ($body['fecha_limite'] ?? ''));
$items = $body['items'] ?? [];

// Opcional — a nivel de pedido completo, varía por marketplace. Ausente o
// null se guarda tal cual como NULL (no se inventa un valor).
$comisionRaw = $body['comision'] ?? null;
$comisionPlataforma = $comisionRaw !== null ? (float) $comisionRaw : null;

if (
    $canalCodigo === ''
    || $codigoOrden === ''
    || $clienteNombre === ''
    || $fechaLimiteRaw === ''
    || !is_array($items)
    || empty($items)
) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Faltan campos obligatorios (canal, codigo_orden, cliente_nombre, fecha_limite, items).']);
    exit;
}

// codigo_orden viene de un sistema externo y más adelante se usa para
// nombrar el archivo de la etiqueta PDF (api/pedido_subir_etiqueta.php) —
// se valida el formato acá, en el punto de entrada, en vez de confiar en
// que siempre venga limpio.
if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $codigoOrden)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => "codigo_orden inválido: solo letras, números, '_' y '-', máx. 40 caracteres."]);
    exit;
}

$timestamp = strtotime($fechaLimiteRaw);
if ($timestamp === false) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'fecha_limite inválida.']);
    exit;
}
$fechaLimite = date('Y-m-d H:i:s', $timestamp);

$pdo = Database::getConnection();

// Whitelist real contra la tabla — nunca se acepta cualquier string como
// canal.
$stmtCanal = $pdo->prepare('SELECT id FROM canales WHERE codigo = ? AND activo = 1 LIMIT 1');
$stmtCanal->execute([$canalCodigo]);
$canalId = $stmtCanal->fetchColumn();

if (!$canalId) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => "Canal '{$canalCodigo}' no reconocido."]);
    exit;
}

$itemsNormalizados = [];
foreach ($items as $item) {
    $producto = trim((string) ($item['producto'] ?? ''));
    if ($producto === '') {
        continue;
    }

    // La extensión captura el pedido en 1 clic desde el marketplace y no
    // siempre tiene el precio a mano en ese momento — si no viene, cuenta
    // como 0 (columna precio_unitario es NOT NULL en la BD) y el monto
    // real se completa/corrige a mano si hace falta.
    $precioItemRaw = $item['precio_unitario'] ?? null;

    $itemsNormalizados[] = [
        'producto_nombre' => $producto,
        'variante'        => trim((string) ($item['variante'] ?? '')),
        'sku'              => trim((string) ($item['sku'] ?? '')),
        'cantidad'         => max(1, (int) ($item['cantidad'] ?? 1)),
        'precio_unitario'  => $precioItemRaw !== null ? (float) $precioItemRaw : 0.0,
    ];
}

if (empty($itemsNormalizados)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'items vacío o sin nombres de producto válidos.']);
    exit;
}

try {
    $pedidoId = PedidoRepository::crear([
        'codigo_orden'             => $codigoOrden,
        'canal_id'                 => $canalId,
        'cliente_nombre'           => $clienteNombre,
        'cliente_dni'              => $clienteDni ?: null,
        'cliente_telefono'         => null,
        'cliente_email'            => null,
        'cliente_direccion'        => $clienteDireccion ?: null,
        // No se pasa 'monto_total': PedidoRepository::crear() lo calcula
        // sumando cantidad*precio_unitario de los items.
        'comision_plataforma'      => $comisionPlataforma,
        'fecha_limite'             => $fechaLimite,
        'metodo_despacho_id'       => null, // se completa en Verificación (para marketplace, probablemente punto_acopio, pero lo confirma un humano)
        'requiere_verificar_pago'  => false,
        'origen'                   => 'extension_chrome',
        'usuario_creador_id'       => null,
        'items'                    => $itemsNormalizados,
    ]);

    echo json_encode(['ok' => true, 'pedido_id' => $pedidoId]);
} catch (PedidoDuplicadoException $e) {
    error_log('[extension_pedido.php] Pedido duplicado, codigo_orden=' . $codigoOrden . ': ' . $e->getMessage());
    echo json_encode(['ok' => true, 'duplicado' => true]);
} catch (Throwable $e) {
    error_log('[extension_pedido.php] Error al crear pedido: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error al procesar el pedido.']);
}
