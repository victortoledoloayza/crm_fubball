<?php
/**
 * scripts/seed_historico_demo.php
 *
 * Inserta ~60 pedidos de demo ya 'facturado', repartidos en los últimos
 * 30 días (más densos en la última semana), para poder ver el Panel con
 * datos antes de tener volumen real. Mismo espíritu que
 * generarHistorico() del prototipo, pero insertado en la BD real.
 *
 * Uso:
 *   php scripts/seed_historico_demo.php
 *
 * Limpieza (todos los pedidos de esta demo quedan marcados
 * origen='demo', para poder borrarlos después sin tocar datos reales):
 *   DELETE FROM pedidos WHERE origen = 'demo';
 */

if (PHP_SAPI !== 'cli') {
    die("Este script solo puede ejecutarse por línea de comandos.\n");
}

require_once __DIR__ . '/../core/util/env.php';

loadEnv(__DIR__ . '/../.env');

// Protección obligatoria: nunca se corre contra production, sin
// excepciones — esto es solo para poder ver el Panel con datos en Local.
if (env('APP_ENV') !== 'local') {
    fwrite(STDERR, "Este script solo puede correr con APP_ENV=local. APP_ENV actual: '" . env('APP_ENV', '') . "'.\n");
    exit(1);
}

require_once __DIR__ . '/../core/db/Database.php';

$pdo = Database::getConnection();

$canalIds = $pdo->query('SELECT id FROM canales WHERE activo = 1')->fetchAll(PDO::FETCH_COLUMN);
$metodoIds = $pdo->query('SELECT id FROM metodos_despacho WHERE activo = 1')->fetchAll(PDO::FETCH_COLUMN);

if (empty($canalIds) || empty($metodoIds)) {
    fwrite(STDERR, "No hay canales o métodos de despacho activos en la BD — corre primero fubball_kds_schema.sql.\n");
    exit(1);
}

$productosPool = [
    ['nombre' => 'Chimpunes Fubball Pro X', 'variante' => 'Talla 42 / Negro', 'sku' => 'CHI-PROX-42', 'precio' => 169.90],
    ['nombre' => 'Medias Fútbol Compresión', 'variante' => 'Talla M (3 pares)', 'sku' => 'MED-COMP-M', 'precio' => 45.00],
    ['nombre' => 'Balón N°5 Matchball', 'variante' => 'Blanco/Azul', 'sku' => 'BAL-N5-BA', 'precio' => 89.90],
    ['nombre' => 'Guantes Portero Elite', 'variante' => 'Talla 8 / Negro', 'sku' => 'GPE-T8-NEG', 'precio' => 129.90],
    ['nombre' => 'Polo Entrenamiento Dry', 'variante' => 'Talla L', 'sku' => 'POL-DRY-L', 'precio' => 59.90],
    ['nombre' => 'Zapatillas Running Fubball X', 'variante' => 'Talla 40', 'sku' => 'ZAP-RUN-40', 'precio' => 249.90],
    ['nombre' => 'Pelota Vóley Pro', 'variante' => 'Oficial', 'sku' => 'PEL-VOL-PRO', 'precio' => 79.90],
    ['nombre' => 'Rodilleras Vóley', 'variante' => 'Talla M', 'sku' => 'ROD-VOL-M', 'precio' => 39.90],
    ['nombre' => 'Camiseta Selección Retro', 'variante' => 'Talla L', 'sku' => 'CAM-RETRO-L', 'precio' => 119.90],
];
$nombresPool = [
    'Luis Fernández Soto', 'Katherine Vega Ponce', 'Aldo Ramírez Bazán',
    'Milagros Chávez Ruiz', 'Sebastián Ortega León', 'Renzo Alva Delgado',
    'Carla Ríos Mendoza', 'Fernando Salazar Quiroz',
];

$stmtPedido = $pdo->prepare(
    'INSERT INTO pedidos
        (codigo_orden, canal_id, cliente_nombre, cliente_dni, cliente_direccion,
         monto_total, fecha_limite, estado, metodo_despacho_id, requiere_verificar_pago,
         resultado, tiempo_despacho_minutos, origen, creado_en, despachado_en, facturado_en)
     VALUES
        (?, ?, ?, ?, \'Dirección de entrega, Lima\',
         ?, ?, \'facturado\', ?, ?,
         ?, ?, \'demo\', ?, ?, ?)'
);
$stmtItem = $pdo->prepare(
    'INSERT INTO pedido_items (pedido_id, producto_nombre, variante, sku, cantidad, precio_unitario)
     VALUES (?, ?, ?, ?, 1, ?)'
);

$pdo->beginTransaction();

$totalInsertados = 0;

try {
    // Igual que generarHistorico() del prototipo: más pedidos en la
    // última semana que en el resto del mes.
    for ($diasAtras = 1; $diasAtras <= 30; $diasAtras++) {
        $pedidosDelDia = $diasAtras <= 7 ? random_int(2, 4) : random_int(1, 2);

        for ($i = 0; $i < $pedidosDelDia; $i++) {
            $producto = $productosPool[array_rand($productosPool)];
            $canalId = $canalIds[array_rand($canalIds)];
            $metodoId = $metodoIds[array_rand($metodoIds)];
            $cliente = $nombresPool[array_rand($nombresPool)];

            $horaAleatoria = random_int(8, 17);
            $minutoAleatorio = random_int(0, 59);
            $creadoEn = (new DateTimeImmutable("-{$diasAtras} days"))
                ->setTime($horaAleatoria, $minutoAleatorio, 0);

            // ~87% de éxito, para que "ocurrencias"/tasa de éxito tengan
            // un caso real que mostrar y no solo el 100%.
            $exitoso = (random_int(1, 100) <= 87);
            $tiempoDespachoMinutos = $exitoso
                ? random_int(35, 185)
                : random_int(180, 440);

            $fechaLimite = $creadoEn->modify('+' . random_int(4, 24) . ' hours');
            $despachadoEn = $creadoEn->modify("+{$tiempoDespachoMinutos} minutes");
            $facturadoEn = $despachadoEn->modify('+' . random_int(5, 120) . ' minutes');

            $codigoOrden = 'DEMO-' . $creadoEn->format('Ymd') . '-' . str_pad((string) ($totalInsertados + 1), 4, '0', STR_PAD_LEFT);

            $stmtPedido->execute([
                $codigoOrden,
                $canalId,
                $cliente,
                (string) random_int(40000000, 48999999),
                $producto['precio'],
                $fechaLimite->format('Y-m-d H:i:s'),
                $metodoId,
                $exitoso ? 0 : 1,
                $exitoso ? 'exitoso' : 'observacion',
                $tiempoDespachoMinutos,
                $creadoEn->format('Y-m-d H:i:s'),
                $despachadoEn->format('Y-m-d H:i:s'),
                $facturadoEn->format('Y-m-d H:i:s'),
            ]);

            $pedidoId = (int) $pdo->lastInsertId();

            $stmtItem->execute([
                $pedidoId,
                $producto['nombre'],
                $producto['variante'],
                $producto['sku'],
                $producto['precio'],
            ]);

            $totalInsertados++;
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error al insertar el histórico de demo: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Listo: {$totalInsertados} pedidos de demo insertados (origen='demo'), repartidos en los últimos 30 días.\n";
echo "Para borrarlos después: DELETE FROM pedidos WHERE origen = 'demo';\n";
