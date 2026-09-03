<?php
/**
 * api/pedidos_editar.php (POST, formulario estándar)
 *
 * Procesa pedidos_editar.php. Solo rol 'admin' — mismo helper que usa la
 * página pedidos_editar.php y usuarios.php (Auth::requireRole corta con
 * 403 antes de tocar el body, el CSRF o el pedido_id).
 *
 * monto_total se recalcula siempre en servidor a partir de los items
 * recibidos — nunca se confía un total que venga del cliente, igual que
 * en pedidos_crear.php. No se toca codigo_orden, estado ni
 * etiqueta_pdf_url — la edición nunca regenera la etiqueta sola (ver
 * PedidoRepository::actualizar()).
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/pedidos/PedidoRepository.php';

$usuarioSesion = Auth::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . baseUrl('pedidos.php'));
    exit;
}

$pedidoId = filter_input(INPUT_POST, 'pedido_id', FILTER_VALIDATE_INT);
if (!$pedidoId) {
    http_response_code(400);
    die('Falta pedido_id.');
}

$redirigirConError = function (string $mensaje) use ($pedidoId): void {
    header('Location: ' . baseUrl('pedidos_editar.php') . '?id=' . $pedidoId . '&error=' . urlencode($mensaje));
    exit;
};

csrfRequerir();

$canalId = filter_input(INPUT_POST, 'canal_id', FILTER_VALIDATE_INT);
$clienteNombre = trim($_POST['cliente_nombre'] ?? '');
$clienteDni = trim($_POST['cliente_dni'] ?? '');
$clienteTelefono = trim($_POST['cliente_telefono'] ?? '');
$clienteEmail = trim($_POST['cliente_email'] ?? '');
$clienteDireccion = trim($_POST['cliente_direccion'] ?? '');
$fechaLimite = trim($_POST['fecha_limite'] ?? '');
$metodoDespachoId = filter_input(INPUT_POST, 'metodo_despacho_id', FILTER_VALIDATE_INT);
$requiereVerificarPago = isset($_POST['requiere_verificar_pago']);
// codigo_orden NO es editable — cualquier valor que venga en el POST
// (ej. de una re-carga de PDF de TSI) se ignora a propósito, ver
// core/ui/formulario_pedido.php.
$costoEnvio = filter_input(INPUT_POST, 'costo_envio', FILTER_VALIDATE_FLOAT);
$moneda = trim($_POST['moneda'] ?? 'PEN');
$itemsPost = $_POST['items'] ?? [];

$errores = [];

if ($costoEnvio === false || $costoEnvio === null) {
    $costoEnvio = 0.0;
} elseif ($costoEnvio < 0) {
    $errores[] = 'El costo de envío no puede ser negativo.';
}

if (!in_array($moneda, ['PEN', 'USD'], true)) {
    $errores[] = 'Moneda no válida.';
}

if (!$canalId) {
    $errores[] = 'Selecciona un canal.';
}
if ($clienteNombre === '') {
    $errores[] = 'El nombre del cliente es obligatorio.';
}
if ($fechaLimite === '') {
    $errores[] = 'La fecha límite es obligatoria.';
} else {
    // El <input type="datetime-local"> manda "YYYY-MM-DDTHH:MM"; MySQL
    // espera "YYYY-MM-DD HH:MM:SS".
    $timestamp = strtotime($fechaLimite);
    if ($timestamp === false) {
        $errores[] = 'La fecha límite no es válida.';
    } else {
        $fechaLimite = date('Y-m-d H:i:s', $timestamp);
    }
}

$items = [];
foreach ($itemsPost as $item) {
    $producto = trim($item['producto_nombre'] ?? '');
    $cantidad = filter_var($item['cantidad'] ?? null, FILTER_VALIDATE_INT);
    $precio = filter_var($item['precio_unitario'] ?? null, FILTER_VALIDATE_FLOAT);

    // Filas vacías (el usuario agregó una fila de más y no la llenó) se
    // ignoran en vez de rechazar todo el formulario.
    if ($producto === '' && $cantidad === false && $precio === false) {
        continue;
    }

    if ($producto === '' || !$cantidad || $cantidad < 1 || $precio === false || $precio < 0) {
        $errores[] = 'Cada producto necesita nombre, cantidad (mínimo 1) y precio válidos.';
        continue;
    }

    $items[] = [
        'producto_nombre' => $producto,
        'variante'        => trim($item['variante'] ?? ''),
        'sku'              => trim($item['sku'] ?? ''),
        'cantidad'         => $cantidad,
        'precio_unitario'  => $precio,
    ];
}

if (empty($items)) {
    $errores[] = 'Agrega al menos un producto.';
}

if (!empty($errores)) {
    $redirigirConError(implode(' ', $errores));
}

try {
    PedidoRepository::actualizar($pedidoId, [
        'canal_id'                => $canalId,
        'cliente_nombre'          => $clienteNombre,
        'cliente_dni'              => $clienteDni,
        'cliente_telefono'         => $clienteTelefono,
        'cliente_email'            => $clienteEmail,
        'cliente_direccion'        => $clienteDireccion,
        'costo_envio'              => $costoEnvio,
        'moneda'                   => $moneda,
        'fecha_limite'             => $fechaLimite,
        'metodo_despacho_id'       => $metodoDespachoId ?: null,
        'requiere_verificar_pago'  => $requiereVerificarPago,
        'items'                    => $items,
    ], (int) $usuarioSesion['id']);

    error_log("[pedidos_editar.php] Pedido id={$pedidoId} editado por usuario id={$usuarioSesion['id']}");
    header('Location: ' . baseUrl('pedidos.php') . '?ok=editado');
    exit;
} catch (PedidoTransicionInvalidaException $e) {
    $redirigirConError($e->getMessage());
} catch (Throwable $e) {
    error_log('[pedidos_editar.php] Error al editar pedido: ' . $e->getMessage());
    $redirigirConError('Ocurrió un error al guardar los cambios.');
}
