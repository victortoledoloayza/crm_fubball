<?php
/**
 * api/despacho_listar.php (GET)
 *
 * Pedidos en estado 'despacho', para la Cola de Despacho.
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/pedidos/PedidoRepository.php';

Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

echo json_encode(['ok' => true, 'pedidos' => PedidoRepository::listarDespacho()]);
