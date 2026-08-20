<?php
/**
 * api/panel_datos.php (GET)
 *
 * Todo el Panel en un solo fetch: stats operativos, KPIs de
 * trazabilidad (últimos 30 días) y los 4 gráficos. Solo lectura.
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/pedidos/PedidoRepository.php';

Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'ok'             => true,
    'operacion'      => PedidoRepository::obtenerStatsOperativos(),
    'trazabilidad'   => PedidoRepository::obtenerKpisTrazabilidad(),
    'chartCanal'     => PedidoRepository::obtenerChartCanal(),
    'chartSla'       => PedidoRepository::obtenerChartSla(),
    'chartMetodo'    => PedidoRepository::obtenerChartMetodo(),
    'chartResultado' => PedidoRepository::obtenerChartResultado(),
]);
