<?php
/**
 * api/pedidos_listar.php (GET)
 *
 * ?estados=nuevo,embalando,verificacion
 *
 * Devuelve JSON con los pedidos en esos estados (whitelisted contra el
 * ENUM real de pedidos.estado), cada uno con su canal, responsables e
 * items.
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/pedidos/PedidoRepository.php';

Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

const ESTADOS_VALIDOS = [
    'nuevo', 'embalando', 'verificacion', 'despacho',
    'facturacion_pendiente', 'facturado', 'cancelado',
];

$estadosParam = $_GET['estados'] ?? '';
$estados = array_values(array_filter(array_map('trim', explode(',', $estadosParam))));

if (empty($estados)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'El parámetro estados es obligatorio.']);
    exit;
}

$estadosInvalidos = array_diff($estados, ESTADOS_VALIDOS);
if (!empty($estadosInvalidos)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Estado(s) inválido(s): ' . implode(', ', $estadosInvalidos)]);
    exit;
}

echo json_encode(['ok' => true, 'pedidos' => PedidoRepository::listarPorEstado(...$estados)]);
