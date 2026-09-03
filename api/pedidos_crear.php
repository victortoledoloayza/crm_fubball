<?php
/**
 * api/pedidos_crear.php (POST, formulario estándar)
 *
 * Procesa pedidos_nuevo.php. `origen` siempre se guarda como 'manual'.
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/pedidos/PedidoRepository.php';

$usuarioSesion = Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . baseUrl('pedidos_nuevo.php'));
    exit;
}

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
// Solo llega con valor cuando el alta vino de "Cargar desde PDF de Orden
// TSI" (ver pedidos_nuevo.php) — en alta manual normal el campo queda
// vacío y PedidoRepository::crear() genera el MANUAL-... de siempre.
$codigoOrden = trim($_POST['codigo_orden'] ?? '');
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
foreach ($itemsPost as $index => $item) {
    $numeroFila = $index + 1;
    $producto = trim($item['producto_nombre'] ?? '');
    $cantidad = filter_var($item['cantidad'] ?? null, FILTER_VALIDATE_INT);
    $precio = filter_var($item['precio_unitario'] ?? null, FILTER_VALIDATE_FLOAT);
    $imagenUrl = trim($item['imagen_url'] ?? '');

    // Filas vacías (el usuario agregó una fila de más y no la llenó) se
    // ignoran en vez de rechazar todo el formulario.
    if ($producto === '' && $cantidad === false && $precio === false) {
        continue;
    }

    if ($producto === '' || !$cantidad || $cantidad < 1 || $precio === false || $precio < 0) {
        $errores[] = 'Cada producto necesita nombre, cantidad (mínimo 1) y precio válidos.';
        continue;
    }

    // Solo valida FORMATO de URL (esquema/estructura) — no hace una
    // llamada de red para comprobar que la imagen realmente cargue, sería
    // un costo innecesario en el guardado del pedido.
    if ($imagenUrl !== '' && filter_var($imagenUrl, FILTER_VALIDATE_URL) === false) {
        $errores[] = "La URL de imagen del producto {$numeroFila} no es válida.";
        continue;
    }

    $items[] = [
        'producto_nombre' => $producto,
        'variante'        => trim($item['variante'] ?? ''),
        'sku'              => trim($item['sku'] ?? ''),
        'cantidad'         => $cantidad,
        'precio_unitario'  => $precio,
        'imagen_url'       => $imagenUrl !== '' ? $imagenUrl : null,
    ];
}

if (empty($items)) {
    $errores[] = 'Agrega al menos un producto.';
}

if (!empty($errores)) {
    header('Location: ' . baseUrl('pedidos_nuevo.php') . '?error=' . urlencode(implode(' ', $errores)));
    exit;
}

try {
    $pedidoId = PedidoRepository::crear([
        'codigo_orden'             => $codigoOrden !== '' ? $codigoOrden : null,
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
        'origen'                   => 'manual',
        'usuario_creador_id'       => (int) $usuarioSesion['id'],
        'items'                    => $items,
    ]);

    error_log("[pedidos_crear.php] Pedido id={$pedidoId} creado manualmente por usuario id={$usuarioSesion['id']}");
    header('Location: ' . baseUrl('pedidos.php') . '?ok=creado');
    exit;
} catch (Throwable $e) {
    error_log('[pedidos_crear.php] Error al crear pedido: ' . $e->getMessage());
    header('Location: ' . baseUrl('pedidos_nuevo.php') . '?error=' . urlencode('Ocurrió un error al guardar el pedido.'));
    exit;
}
