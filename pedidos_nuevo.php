<?php
/**
 * pedidos_nuevo.php
 *
 * Alta manual de pedidos: mientras no hay integraciones reales (Shopify /
 * extensión llegan en Fase 7), sirve para cargar pedidos de prueba y para
 * registrar a mano un pedido tomado por teléfono. El formulario en sí vive
 * en core/ui/formulario_pedido.php, compartido con pedidos_editar.php.
 */

require_once __DIR__ . '/core/bootstrap.php';

Auth::requireLogin();

$pdo = Database::getConnection();
$canales = $pdo->query('SELECT id, codigo, nombre FROM canales WHERE activo = 1 ORDER BY nombre')->fetchAll();
$metodosDespacho = $pdo->query('SELECT id, nombre FROM metodos_despacho WHERE activo = 1 ORDER BY nombre')->fetchAll();

$error = $_GET['error'] ?? '';

$modoEdicion = false;
$pedidoEnEdicion = null;
$accionFormulario = baseUrl('api/pedidos_crear.php');
$tituloFormulario = 'Nuevo pedido';
$subtituloFormulario = 'Carga manual — úsalo para pedidos tomados por teléfono o para pruebas del tablero.';
$textoBotonSubmit = 'Crear pedido';

$tituloPagina = 'Nuevo pedido';
$navActiva    = 'tablero';
require __DIR__ . '/core/ui/layout_header.php';
require __DIR__ . '/core/ui/formulario_pedido.php';
require __DIR__ . '/core/ui/layout_footer.php';
