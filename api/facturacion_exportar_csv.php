<?php
/**
 * api/facturacion_exportar_csv.php (GET)
 *
 * CSV del lote completo de pedidos en 'facturacion_pendiente', para
 * pegar en TSI mientras no tenga API abierta. Solo lectura — no cambia
 * ningún estado.
 *
 * fputcsv() sobre php://output en vez de armar el string a mano: es más
 * robusto ante nombres de cliente/producto con comas o comillas (dato
 * real de usuario, no de demo).
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/pedidos/PedidoRepository.php';

Auth::requireLogin();

$pedidos = PedidoRepository::listarFacturacionPendiente();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="TSI_Fubball_' . date('Y-m-d') . '.csv"');

$salida = fopen('php://output', 'w');

// BOM UTF-8: para que Excel (el destino declarado del botón) detecte la
// codificación y no rompa tildes/eñes al abrir el archivo directo.
fwrite($salida, "\xEF\xBB\xBF");

fputcsv($salida, ['Nombre', 'DNI', 'Direccion', 'Producto', 'SKU', 'Cantidad', 'Precio', 'Canal', 'N Orden']);

foreach ($pedidos as $p) {
    $productoTxt = implode(' + ', array_map(
        fn (array $it): string => $it['variante'] ? "{$it['producto_nombre']} - {$it['variante']}" : $it['producto_nombre'],
        $p['items']
    ));
    $skuTxt = implode(' + ', array_map(fn (array $it): string => (string) $it['sku'], $p['items']));
    $cantidadTotal = array_sum(array_map(fn (array $it): int => (int) $it['cantidad'], $p['items']));

    fputcsv($salida, [
        $p['cliente_nombre'],
        $p['cliente_dni'],
        $p['cliente_direccion'],
        $productoTxt,
        $skuTxt,
        $cantidadTotal,
        number_format((float) $p['monto_total'], 2, '.', ''),
        $p['canal_nombre'],
        $p['codigo_orden'],
    ]);
}

fclose($salida);
