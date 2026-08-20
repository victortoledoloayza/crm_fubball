<?php
/**
 * api/facturacion_listar.php (GET)
 *
 * Pedidos en estado 'facturacion_pendiente', para la Cola de Facturación.
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/pedidos/PedidoRepository.php';

Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

echo json_encode(['ok' => true, 'pedidos' => PedidoRepository::listarFacturacionPendiente()]);
