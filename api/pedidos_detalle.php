<?php
/**
 * api/pedidos_detalle.php (GET)
 *
 * ?id=123 — un pedido con sus items, para el modal "Ver detalle para
 * embalar" del Tablero KDS.
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/pedidos/PedidoRepository.php';

Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id === null || $id === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'id inválido.']);
    exit;
}

$pedido = PedidoRepository::obtener($id);

if ($pedido === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Pedido no encontrado.']);
    exit;
}

echo json_encode(['ok' => true, 'pedido' => $pedido]);
