<?php
/**
 * pedidos_editar.php?id=N
 *
 * Edición de un pedido existente — mismo formulario que pedidos_nuevo.php
 * (core/ui/formulario_pedido.php), pre-llenado con los datos actuales.
 * Solo rol 'admin', igual que "Eliminar" — el chequeo va en la primera
 * línea después de bootstrap, antes de tocar cualquier otra cosa.
 *
 * Bloqueado para pedidos en 'facturado' o 'cancelado': son estados
 * terminales y facturado ya tiene un monto_total reflejado en KPIs de
 * trazabilidad — editar items/precio ahí desincronizaría el registro de
 * lo que realmente se facturó. La API (api/pedidos_editar.php) hace
 * cumplir esto también, esta página solo evita mostrar un formulario que
 * el servidor va a rechazar igual.
 */

require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/core/pedidos/PedidoRepository.php';

Auth::requireRole('admin');

$pedidoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$pedidoId) {
    http_response_code(400);
    die('Falta el id del pedido.');
}

$pedido = PedidoRepository::obtener($pedidoId);
if ($pedido === null) {
    http_response_code(404);
    die('Pedido no encontrado.');
}

$estadosBloqueados = ['facturado', 'cancelado'];
$bloqueadoPorEstado = in_array($pedido['estado'], $estadosBloqueados, true);

$pdo = Database::getConnection();
$canales = $pdo->query('SELECT id, codigo, nombre FROM canales WHERE activo = 1 ORDER BY nombre')->fetchAll();
$metodosDespacho = $pdo->query('SELECT id, nombre FROM metodos_despacho WHERE activo = 1 ORDER BY nombre')->fetchAll();

$error = $_GET['error'] ?? '';

$modoEdicion = true;
$pedidoEnEdicion = $pedido;
$accionFormulario = baseUrl('api/pedidos_editar.php');
$tituloFormulario = 'Editar pedido #' . $pedido['codigo_orden'];
$subtituloFormulario = 'Todos los campos son editables. El código de orden y el estado del flujo no cambian desde acá.';
$textoBotonSubmit = 'Guardar cambios';

$tituloPagina = 'Editar pedido';
$navActiva    = 'tablero';
require __DIR__ . '/core/ui/layout_header.php';

if ($bloqueadoPorEstado):
?>
    <div class="mensaje-error" style="background:#fdecec;border:1px solid #f6b8b3;color:#a3231a;padding:14px 16px;border-radius:8px;font-size:13.5px;max-width:720px;">
        Este pedido está en estado "<?= htmlspecialchars($pedido['estado'], ENT_QUOTES, 'UTF-8') ?>" y ya no se puede editar
        — <?= $pedido['estado'] === 'facturado' ? 'ya fue facturado, así que su monto_total quedó fijado en el registro contable.' : 'está cancelado.' ?>
    </div>
<?php
else:
    require __DIR__ . '/core/ui/formulario_pedido.php';
endif;

require __DIR__ . '/core/ui/layout_footer.php';
