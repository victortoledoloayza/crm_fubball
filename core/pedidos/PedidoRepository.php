<?php
/**
 * core/pedidos/PedidoRepository.php
 *
 * Acceso a datos de `pedidos` / `pedido_items` / `pedido_eventos`.
 *
 * Columnas relevantes (ver fubball_kds_schema.sql):
 *   pedidos.estado ENUM('nuevo','embalando','verificacion','despacho',
 *     'facturacion_pendiente','facturado','cancelado')
 *   pedidos.responsable_embalaje_id / responsable_verificacion_id /
 *     responsable_despacho_id -> usuarios.id
 *
 * Todas las queries usan prepared statements — ningún valor de usuario va
 * concatenado directo en el SQL. La única excepción es el nombre de
 * columna `responsable_{$campo}_id` en avanzarFase(), que se arma a
 * partir de un valor validado contra una whitelist fija (PDO no permite
 * parametrizar nombres de columna).
 */

require_once __DIR__ . '/ReglaSlaCalculator.php';
require_once __DIR__ . '/PrioridadCalculator.php';

class PedidoTransicionInvalidaException extends RuntimeException
{
}

// codigo_orden ya existe (choque de UNIQUE) — se distingue de una
// excepción SQL genérica porque los webhooks (Shopify, extensión Chrome)
// necesitan tratarla como éxito idempotente (200, "ya lo procesamos
// antes"), no como un error real.
class PedidoDuplicadoException extends RuntimeException
{
}

class PedidoRepository
{
    // Orden del flujo: de qué estado se puede pasar a cuál, y qué columna
    // de responsable corresponde a esa transición.
    private const TRANSICIONES = [
        'nuevo'        => ['siguiente' => 'embalando',    'campo' => 'embalaje'],
        'embalando'    => ['siguiente' => 'verificacion', 'campo' => 'verificacion'],
        'verificacion' => ['siguiente' => 'despacho',      'campo' => 'despacho'],
    ];

    // Inverso de TRANSICIONES + los dos pasos que avanzarFase() no cubre
    // (despacho y facturacion_pendiente, que avanzan vía marcarDespachado()
    // / marcarFacturado()) — usado por retrocederFase() para poder
    // corregir un error operativo desde cualquier fase salvo 'nuevo', que
    // es el inicio del flujo y no tiene fase anterior.
    private const TRANSICIONES_INVERSAS = [
        'embalando'             => 'nuevo',
        'verificacion'          => 'embalando',
        'despacho'              => 'verificacion',
        'facturacion_pendiente' => 'despacho',
    ];

    // Recalcula `prioridad` en bloque para todos los pedidos activos con
    // prioridad_manual=0, según el tiempo restante hasta fecha_limite
    // (interna) — ver PrioridadCalculator. Se llama desde
    // api/pedidos_listar.php, que ya se pide cada 30s desde el tablero
    // (setInterval existente) — aprovecha ese ciclo en vez de crear un
    // mecanismo de recálculo aparte. Un pedido con prioridad_manual=1
    // (alguien lo cambió a mano) nunca se toca acá.
    public static function recalcularPrioridadesAutomaticas(): void
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            "UPDATE pedidos
             SET prioridad = CASE
                 WHEN fecha_limite < NOW() + INTERVAL ? MINUTE THEN 'muy_urgente'
                 WHEN fecha_limite < NOW() + INTERVAL ? MINUTE THEN 'urgente'
                 ELSE 'normal'
             END
             WHERE prioridad_manual = 0 AND estado NOT IN ('facturado', 'cancelado')"
        );
        $stmt->execute([
            PrioridadCalculator::UMBRAL_MUY_URGENTE_MINUTOS,
            PrioridadCalculator::UMBRAL_URGENTE_MINUTOS,
        ]);
    }

    // Sobreescritura manual desde el tablero — a partir de acá el pedido
    // queda "fijado" en esta prioridad: recalcularPrioridadesAutomaticas()
    // nunca la vuelve a tocar hasta que alguien la cambie de nuevo a mano
    // (o, si hiciera falta más adelante, algo la vuelva a poner en 0).
    public static function actualizarPrioridadManual(int $pedidoId, string $prioridad): void
    {
        if (!in_array($prioridad, ['normal', 'urgente', 'muy_urgente'], true)) {
            throw new InvalidArgumentException("Prioridad inválida: '{$prioridad}'.");
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE pedidos SET prioridad = ?, prioridad_manual = 1 WHERE id = ?');
        $stmt->execute([$prioridad, $pedidoId]);

        if ($stmt->rowCount() === 0) {
            throw new PedidoTransicionInvalidaException("El pedido #{$pedidoId} no existe.");
        }
    }

    public static function listarPorEstado(string ...$estados): array
    {
        $pdo = Database::getConnection();

        $marcadores = implode(',', array_fill(0, count($estados), '?'));
        $stmt = $pdo->prepare(
            "SELECT p.*,
                    c.codigo AS canal_codigo, c.nombre AS canal_nombre, c.color_hex AS canal_color,
                    ue.nombre AS resp_embalaje_nombre,
                    uv.nombre AS resp_verificacion_nombre,
                    ud.nombre AS resp_despacho_nombre
             FROM pedidos p
             INNER JOIN canales c ON c.id = p.canal_id
             LEFT JOIN usuarios ue ON ue.id = p.responsable_embalaje_id
             LEFT JOIN usuarios uv ON uv.id = p.responsable_verificacion_id
             LEFT JOIN usuarios ud ON ud.id = p.responsable_despacho_id
             WHERE p.estado IN ({$marcadores})
             ORDER BY p.fecha_limite ASC"
        );
        $stmt->execute($estados);
        $filas = $stmt->fetchAll();

        return self::adjuntarItems($pdo, $filas);
    }

    public static function obtener(int $id): ?array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'SELECT p.*,
                    c.codigo AS canal_codigo, c.nombre AS canal_nombre, c.color_hex AS canal_color,
                    md.nombre AS metodo_nombre,
                    ue.nombre AS resp_embalaje_nombre,
                    uv.nombre AS resp_verificacion_nombre,
                    ud.nombre AS resp_despacho_nombre
             FROM pedidos p
             INNER JOIN canales c ON c.id = p.canal_id
             LEFT JOIN metodos_despacho md ON md.id = p.metodo_despacho_id
             LEFT JOIN usuarios ue ON ue.id = p.responsable_embalaje_id
             LEFT JOIN usuarios uv ON uv.id = p.responsable_verificacion_id
             LEFT JOIN usuarios ud ON ud.id = p.responsable_despacho_id
             WHERE p.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $fila = $stmt->fetch();

        if (!$fila) {
            return null;
        }

        $pedidos = self::adjuntarItems($pdo, [$fila]);

        return $pedidos[0];
    }

    // Lookup liviano (sin joins ni items) para integraciones que solo
    // conocen el codigo_orden — ver api/webhooks/pedido_etiqueta.php, que
    // necesita encontrar el pedido para adjuntarle la etiqueta pero no
    // usa el resto de sus datos.
    public static function obtenerPorCodigoOrden(string $codigoOrden): ?array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'SELECT p.id, p.codigo_orden, c.codigo AS canal_codigo
             FROM pedidos p
             INNER JOIN canales c ON c.id = p.canal_id
             WHERE p.codigo_orden = ?
             LIMIT 1'
        );
        $stmt->execute([$codigoOrden]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    // Trae los items de todos los pedidos ya obtenidos en una sola query
    // (WHERE pedido_id IN (...)) y los agrupa en memoria, para evitar el
    // problema N+1 de una query de items por pedido.
    private static function adjuntarItems(PDO $pdo, array $filasPedidos): array
    {
        if (empty($filasPedidos)) {
            return [];
        }

        $ids = array_map(fn (array $p): int => (int) $p['id'], $filasPedidos);
        $marcadores = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare(
            "SELECT * FROM pedido_items WHERE pedido_id IN ({$marcadores}) ORDER BY id"
        );
        $stmt->execute($ids);

        $itemsPorPedido = [];
        foreach ($stmt->fetchAll() as $item) {
            $itemsPorPedido[(int) $item['pedido_id']][] = $item;
        }

        return array_map(function (array $p) use ($itemsPorPedido): array {
            $p['items'] = $itemsPorPedido[(int) $p['id']] ?? [];

            return $p;
        }, $filasPedidos);
    }

    // $datos: canal_id, cliente_nombre, cliente_dni, cliente_telefono,
    // cliente_email, cliente_direccion, fecha_limite, metodo_despacho_id
    // (puede ser NULL — altas automáticas no siempre lo traen todavía,
    // ver avanzarFase()), requiere_verificar_pago, origen,
    // usuario_creador_id (puede ser NULL: los webhooks no tienen un
    // usuario logueado detrás), items: [['producto_nombre','variante',
    // 'sku','cantidad','precio_unitario'], ...]. 'imagen_url' por item es
    // opcional (NULL si no viene o no se pasa la clave).
    //
    // Opcionales (altas automáticas — Shopify, extensión Chrome):
    //   codigo_orden: si no se pasa, se genera uno MANUAL-... (alta manual
    //     desde pedidos_nuevo.php). Si se pasa y ya existe (choque de
    //     UNIQUE), lanza PedidoDuplicadoException — es el caso normal de
    //     un webhook reintentando un pedido que ya se procesó.
    //   monto_total: si no se pasa, se calcula sumando cantidad*precio de
    //     los items (alta manual, extensión Chrome). Shopify manda su
    //     propio total_price (puede incluir envío/impuestos que no están
    //     en los line_items), así que ahí SÍ se pasa explícito.
    //   comision_plataforma: comisión del marketplace para el pedido
    //     completo (dato de margen, no siempre disponible). Si no se
    //     pasa, se guarda NULL.
    public static function crear(array $datos): int
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $montoTotal = array_key_exists('monto_total', $datos)
                ? (float) $datos['monto_total']
                : array_reduce(
                    $datos['items'],
                    fn (float $acc, array $item): float => $acc + ((float) $item['precio_unitario'] * (int) $item['cantidad']),
                    0.0
                );

            $codigoOrden = $datos['codigo_orden'] ?? self::generarCodigoOrdenManual($pdo);
            $comisionPlataforma = array_key_exists('comision_plataforma', $datos) && $datos['comision_plataforma'] !== null
                ? (float) $datos['comision_plataforma']
                : null;
            // Ambos opcionales — los webhooks (Shopify, extensión Chrome) no
            // los mandan todavía, así que caen a los mismos defaults de la
            // columna (0 y 'PEN') en vez de fallar por falta de la clave.
            $costoEnvio = array_key_exists('costo_envio', $datos) && $datos['costo_envio'] !== null
                ? (float) $datos['costo_envio']
                : 0.0;
            $moneda = $datos['moneda'] ?? 'PEN';

            // El único valor de fecha que manda el caller (webhook, alta
            // manual, TSI) se trata como la hora marketplace — si el canal
            // tiene reglas_sla activas, acá se calcula la interna aplicando
            // el colchón; si no, ambas quedan iguales. Nunca se re-ejecuta
            // al editar (ver PedidoRepository::actualizar()).
            $fechasLimite = ReglaSlaCalculator::calcular($pdo, (int) $datos['canal_id'], $datos['fecha_limite']);
            $prioridadInicial = PrioridadCalculator::calcular($fechasLimite['fecha_limite']);

            $stmt = $pdo->prepare(
                'INSERT INTO pedidos
                    (codigo_orden, canal_id, cliente_nombre, cliente_dni, cliente_telefono,
                     cliente_email, cliente_direccion, monto_total, comision_plataforma, costo_envio, moneda,
                     fecha_limite, fecha_limite_marketplace, prioridad, estado, metodo_despacho_id,
                     requiere_verificar_pago, origen)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'nuevo\', ?, ?, ?)'
            );

            try {
                $stmt->execute([
                    $codigoOrden,
                    $datos['canal_id'],
                    $datos['cliente_nombre'],
                    $datos['cliente_dni'] ?: null,
                    $datos['cliente_telefono'] ?: null,
                    $datos['cliente_email'] ?: null,
                    $datos['cliente_direccion'] ?: null,
                    $montoTotal,
                    $comisionPlataforma,
                    $costoEnvio,
                    $moneda,
                    $fechasLimite['fecha_limite'],
                    $fechasLimite['fecha_limite_marketplace'],
                    $prioridadInicial,
                    $datos['metodo_despacho_id'] ?: null,
                    $datos['requiere_verificar_pago'] ? 1 : 0,
                    $datos['origen'],
                ]);
            } catch (PDOException $e) {
                if ((int) $e->errorInfo[1] === 1062) {
                    throw new PedidoDuplicadoException("Ya existe un pedido con codigo_orden '{$codigoOrden}'.");
                }

                throw $e;
            }

            $pedidoId = (int) $pdo->lastInsertId();

            $stmtItem = $pdo->prepare(
                'INSERT INTO pedido_items (pedido_id, producto_nombre, variante, sku, cantidad, precio_unitario, imagen_url)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($datos['items'] as $item) {
                $stmtItem->execute([
                    $pedidoId,
                    $item['producto_nombre'],
                    $item['variante'] ?: null,
                    $item['sku'] ?: null,
                    $item['cantidad'],
                    $item['precio_unitario'],
                    $item['imagen_url'] ?? null,
                ]);
            }

            $stmtEvento = $pdo->prepare(
                'INSERT INTO pedido_eventos (pedido_id, usuario_id, estado_anterior, estado_nuevo)
                 VALUES (?, ?, NULL, \'nuevo\')'
            );
            $stmtEvento->execute([$pedidoId, $datos['usuario_creador_id'] ?? null]);

            $pdo->commit();

            return $pedidoId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // Campos editables desde pedidos_editar.php y su tipo, usado tanto para
    // armar el UPDATE como para detectar qué cambió (ver compararValor()).
    // codigo_orden, estado, origen y las columnas de responsable/timestamps
    // de fase quedan afuera a propósito — esos los maneja el flujo normal
    // (avanzar/retroceder fase, marcarDespachado, etc.), no la edición.
    private const CAMPOS_EDITABLES = [
        'canal_id'                => 'int',
        'cliente_nombre'          => 'str',
        'cliente_dni'             => 'str',
        'cliente_telefono'        => 'str',
        'cliente_email'           => 'str',
        'cliente_direccion'       => 'str',
        'costo_envio'             => 'float',
        'moneda'                  => 'str',
        'fecha_limite'            => 'str',
        'metodo_despacho_id'      => 'int',
        'requiere_verificar_pago' => 'int',
    ];

    // Actualiza los datos editables de un pedido existente + reemplaza sus
    // items (borra e inserta de nuevo — más simple y confiable que
    // diffear altas/bajas/cambios fila por fila). monto_total SIEMPRE se
    // recalcula acá desde los items nuevos, nunca se confía el que venga
    // del cliente. No toca estado/codigo_orden/etiqueta_pdf_url/origen ni
    // las columnas de responsable — eso es responsabilidad del flujo de
    // fases, no de la edición.
    //
    // $datos: mismas claves que necesita crear() para canal_id..items,
    // más 'costo_envio' y 'moneda' (ya validados por el caller).
    public static function actualizar(int $pedidoId, array $datos, int $usuarioQueEjecutaId): void
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT * FROM pedidos WHERE id = ? FOR UPDATE');
            $stmt->execute([$pedidoId]);
            $anterior = $stmt->fetch();

            if ($anterior === false) {
                throw new PedidoTransicionInvalidaException("El pedido #{$pedidoId} no existe.");
            }

            if (in_array($anterior['estado'], ['facturado', 'cancelado'], true)) {
                throw new PedidoTransicionInvalidaException(
                    "El pedido está en estado '{$anterior['estado']}' y ya no se puede editar."
                );
            }

            $montoTotal = array_reduce(
                $datos['items'],
                fn (float $acc, array $item): float => $acc + ((float) $item['precio_unitario'] * (int) $item['cantidad']),
                0.0
            );

            $valoresNuevos = [
                'canal_id'                => $datos['canal_id'],
                'cliente_nombre'          => $datos['cliente_nombre'],
                'cliente_dni'             => $datos['cliente_dni'] ?: null,
                'cliente_telefono'        => $datos['cliente_telefono'] ?: null,
                'cliente_email'           => $datos['cliente_email'] ?: null,
                'cliente_direccion'       => $datos['cliente_direccion'] ?: null,
                'costo_envio'             => $datos['costo_envio'],
                'moneda'                  => $datos['moneda'],
                'fecha_limite'            => $datos['fecha_limite'],
                'metodo_despacho_id'      => $datos['metodo_despacho_id'] ?: null,
                'requiere_verificar_pago' => $datos['requiere_verificar_pago'] ? 1 : 0,
            ];

            $set = implode(', ', array_map(fn (string $campo): string => "{$campo} = ?", array_keys($valoresNuevos)));
            $stmtUpdate = $pdo->prepare("UPDATE pedidos SET monto_total = ?, {$set} WHERE id = ?");
            $stmtUpdate->execute([$montoTotal, ...array_values($valoresNuevos), $pedidoId]);

            $stmtItemsAnteriores = $pdo->prepare(
                'SELECT producto_nombre, variante, sku, cantidad, precio_unitario, imagen_url FROM pedido_items WHERE pedido_id = ? ORDER BY id'
            );
            $stmtItemsAnteriores->execute([$pedidoId]);
            $itemsAnteriores = $stmtItemsAnteriores->fetchAll();

            $pdo->prepare('DELETE FROM pedido_items WHERE pedido_id = ?')->execute([$pedidoId]);
            $stmtItem = $pdo->prepare(
                'INSERT INTO pedido_items (pedido_id, producto_nombre, variante, sku, cantidad, precio_unitario, imagen_url)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($datos['items'] as $item) {
                $stmtItem->execute([
                    $pedidoId,
                    $item['producto_nombre'],
                    $item['variante'] ?: null,
                    $item['sku'] ?: null,
                    $item['cantidad'],
                    $item['precio_unitario'],
                    $item['imagen_url'] ?? null,
                ]);
            }

            $nota = self::describirCambios($anterior, $valoresNuevos, $itemsAnteriores, $datos['items']);

            $stmtEvento = $pdo->prepare(
                'INSERT INTO pedido_eventos (pedido_id, usuario_id, estado_anterior, estado_nuevo, nota)
                 VALUES (?, ?, ?, ?, ?)'
            );
            // Editar no cambia de fase — estado_anterior y estado_nuevo
            // quedan iguales, el evento solo deja constancia de la edición.
            $stmtEvento->execute([$pedidoId, $usuarioQueEjecutaId, $anterior['estado'], $anterior['estado'], $nota]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // "Editado: cliente_direccion, costo_envio, 3 items modificados" — solo
    // lista QUÉ cambió, no los valores antes/después (alcanza para
    // auditoría rápida sin inflar pedido_eventos.nota).
    private static function describirCambios(array $anterior, array $nuevos, array $itemsAnteriores, array $itemsNuevos): string
    {
        $cambios = [];
        foreach (self::CAMPOS_EDITABLES as $campo => $tipo) {
            if (self::valorCambio($anterior[$campo] ?? null, $nuevos[$campo] ?? null, $tipo)) {
                $cambios[] = $campo;
            }
        }

        $firmarItems = static function (array $filas): string {
            $normalizadas = array_map(
                fn (array $it): string => implode('~', [
                    (string) ($it['producto_nombre'] ?? ''),
                    (string) ($it['variante'] ?? ''),
                    (string) ($it['sku'] ?? ''),
                    (string) ($it['cantidad'] ?? ''),
                    number_format((float) ($it['precio_unitario'] ?? 0), 2, '.', ''),
                    (string) ($it['imagen_url'] ?? ''),
                ]),
                $filas
            );

            return implode('|', $normalizadas);
        };

        if ($firmarItems($itemsAnteriores) !== $firmarItems($itemsNuevos)) {
            $cambios[] = count($itemsNuevos) . ' items modificados';
        }

        return 'Editado: ' . (empty($cambios) ? 'sin cambios detectados' : implode(', ', $cambios));
    }

    private static function valorCambio($anterior, $nuevo, string $tipo): bool
    {
        return match ($tipo) {
            'int' => (int) $anterior !== (int) $nuevo,
            'float' => round((float) $anterior, 2) !== round((float) $nuevo, 2),
            default => (string) ($anterior ?? '') !== (string) ($nuevo ?? ''),
        };
    }

    // Códigos de orden reales (marketplaces) llegan en Fase 7 vía
    // integración. Mientras tanto, los pedidos cargados a mano se marcan
    // con un prefijo MANUAL- explícito para que nunca se puedan confundir
    // con un código de orden real de un canal.
    private static function generarCodigoOrdenManual(PDO $pdo): string
    {
        for ($intento = 0; $intento < 5; $intento++) {
            $codigo = 'MANUAL-' . date('Ymd-His') . '-' . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare('SELECT 1 FROM pedidos WHERE codigo_orden = ? LIMIT 1');
            $stmt->execute([$codigo]);

            if (!$stmt->fetchColumn()) {
                return $codigo;
            }
        }

        throw new RuntimeException('No se pudo generar un código de orden único. Intenta de nuevo.');
    }

    // Valida la transición contra el flujo real (nuevo→embalando→
    // verificacion→despacho) antes de tocar la BD. $campoResponsable debe
    // coincidir exactamente con el que corresponde a esa transición —
    // nunca se confía en lo que mande el cliente para el nombre de
    // columna, incluso siendo un valor "razonable".
    // $metodoDespachoId solo se usa (y solo hace falta) en la transición
    // verificación → despacho, para pedidos que llegaron por integración
    // automática sin método de despacho todavía (ver PedidoRepository::crear()
    // y api/webhooks/*.php) — si el pedido ya tenía uno asignado (altas
    // manuales), este parámetro se ignora.
    public static function avanzarFase(
        int $pedidoId,
        string $nuevoEstado,
        string $campoResponsable,
        int $responsableId,
        int $usuarioQueEjecutaId,
        ?int $metodoDespachoId = null
    ): void {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT estado, metodo_despacho_id FROM pedidos WHERE id = ? FOR UPDATE');
            $stmt->execute([$pedidoId]);
            $fila = $stmt->fetch();

            if ($fila === false) {
                throw new PedidoTransicionInvalidaException("El pedido #{$pedidoId} no existe.");
            }

            $estadoActual = $fila['estado'];

            $transicion = self::TRANSICIONES[$estadoActual] ?? null;

            if ($transicion === null) {
                throw new PedidoTransicionInvalidaException(
                    "El pedido está en estado '{$estadoActual}' y no admite más avances desde el Tablero KDS."
                );
            }

            if ($transicion['siguiente'] !== $nuevoEstado || $transicion['campo'] !== $campoResponsable) {
                throw new PedidoTransicionInvalidaException(
                    "Transición inválida: el pedido está en '{$estadoActual}' y debe avanzar a "
                    . "'{$transicion['siguiente']}' (no a '{$nuevoEstado}'). Recarga el tablero e intenta de nuevo."
                );
            }

            $stmtResponsable = $pdo->prepare('SELECT 1 FROM usuarios WHERE id = ? AND activo = 1 LIMIT 1');
            $stmtResponsable->execute([$responsableId]);
            if (!$stmtResponsable->fetchColumn()) {
                throw new PedidoTransicionInvalidaException('El responsable seleccionado no existe o está inactivo.');
            }

            // Único punto del flujo donde el método de despacho puede
            // seguir sin definir (pedidos de Shopify/extensión Chrome): se
            // exige o se valida acá, justo antes de entrar a la Cola de
            // Despacho, que sí lo necesita para todo (badges, acciones).
            $columnasExtra = [];
            $valoresExtra = [];
            if ($nuevoEstado === 'despacho' && $fila['metodo_despacho_id'] === null) {
                if ($metodoDespachoId === null) {
                    throw new PedidoTransicionInvalidaException(
                        'Selecciona el método de despacho antes de continuar.'
                    );
                }

                $stmtMetodo = $pdo->prepare('SELECT 1 FROM metodos_despacho WHERE id = ? AND activo = 1 LIMIT 1');
                $stmtMetodo->execute([$metodoDespachoId]);
                if (!$stmtMetodo->fetchColumn()) {
                    throw new PedidoTransicionInvalidaException('El método de despacho seleccionado no es válido.');
                }

                $columnasExtra[] = 'metodo_despacho_id = ?';
                $valoresExtra[] = $metodoDespachoId;
            }

            // $campoResponsable ya fue validado arriba contra TRANSICIONES
            // (solo puede ser 'embalaje', 'verificacion' o 'despacho').
            $columnaResponsable = "responsable_{$campoResponsable}_id";
            $set = "estado = ?, {$columnaResponsable} = ?" . ($columnasExtra ? ', ' . implode(', ', $columnasExtra) : '');
            $stmtUpdate = $pdo->prepare("UPDATE pedidos SET {$set} WHERE id = ?");
            $stmtUpdate->execute([$nuevoEstado, $responsableId, ...$valoresExtra, $pedidoId]);

            $stmtEvento = $pdo->prepare(
                'INSERT INTO pedido_eventos (pedido_id, usuario_id, estado_anterior, estado_nuevo)
                 VALUES (?, ?, ?, ?)'
            );
            $stmtEvento->execute([$pedidoId, $usuarioQueEjecutaId, $estadoActual, $nuevoEstado]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // Regresa un pedido a la fase anterior — corrige un error operativo
    // (se avanzó de fase por accidente, o hay que reabrir el paso previo)
    // desde el Tablero KDS, la Cola de Despacho o la Cola de Facturación.
    // A diferencia de avanzarFase(), no toca columnas de responsable ni
    // timestamps de fase (metodo_despacho_id, despachado_en, etc.) — se
    // vuelven a completar solos cuando el pedido re-avanza; acá solo se
    // mueve `estado` y se deja constancia en pedido_eventos.
    // $estadoActualEsperado protege contra condiciones de carrera igual
    // que avanzarFase(): si alguien más ya movió el pedido desde que la UI
    // cargó la tarjeta/fila, se rechaza en vez de aplicar el retroceso
    // sobre datos viejos.
    public static function retrocederFase(
        int $pedidoId,
        string $estadoActualEsperado,
        int $usuarioQueEjecutaId
    ): void {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT estado FROM pedidos WHERE id = ? FOR UPDATE');
            $stmt->execute([$pedidoId]);
            $fila = $stmt->fetch();

            if ($fila === false) {
                throw new PedidoTransicionInvalidaException("El pedido #{$pedidoId} no existe.");
            }

            $estadoActual = $fila['estado'];

            if ($estadoActual !== $estadoActualEsperado) {
                throw new PedidoTransicionInvalidaException(
                    "El pedido está en estado '{$estadoActual}', no en '{$estadoActualEsperado}'. "
                    . 'Recarga la página e intenta de nuevo.'
                );
            }

            $estadoAnterior = self::TRANSICIONES_INVERSAS[$estadoActual] ?? null;

            if ($estadoAnterior === null) {
                throw new PedidoTransicionInvalidaException(
                    "El pedido está en estado '{$estadoActual}' y no admite regresar de fase."
                );
            }

            $stmtUpdate = $pdo->prepare('UPDATE pedidos SET estado = ? WHERE id = ?');
            $stmtUpdate->execute([$estadoAnterior, $pedidoId]);

            $stmtEvento = $pdo->prepare(
                "INSERT INTO pedido_eventos (pedido_id, usuario_id, estado_anterior, estado_nuevo, nota)
                 VALUES (?, ?, ?, ?, 'Regresado manualmente')"
            );
            $stmtEvento->execute([$pedidoId, $usuarioQueEjecutaId, $estadoActual, $estadoAnterior]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function listarDespacho(): array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->query(
            "SELECT p.*,
                    c.codigo AS canal_codigo, c.nombre AS canal_nombre, c.color_hex AS canal_color,
                    md.codigo AS metodo_codigo, md.nombre AS metodo_nombre, md.color_hex AS metodo_color,
                    ud.nombre AS resp_despacho_nombre
             FROM pedidos p
             INNER JOIN canales c ON c.id = p.canal_id
             LEFT JOIN metodos_despacho md ON md.id = p.metodo_despacho_id
             LEFT JOIN usuarios ud ON ud.id = p.responsable_despacho_id
             WHERE p.estado = 'despacho'
             ORDER BY p.fecha_limite ASC"
        );

        return $stmt->fetchAll();
    }

    // No cambia el estado del pedido — solo deja constancia de quién
    // coordinó el motorizado y cuándo, para poder habilitar "Marcar
    // entregado" en moto_delivery.
    public static function coordinarMotorizado(int $pedidoId, int $usuarioQueEjecutaId): void
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT p.estado, md.codigo AS metodo_codigo
                 FROM pedidos p
                 LEFT JOIN metodos_despacho md ON md.id = p.metodo_despacho_id
                 WHERE p.id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$pedidoId]);
            $fila = $stmt->fetch();

            if (!$fila) {
                throw new PedidoTransicionInvalidaException("El pedido #{$pedidoId} no existe.");
            }

            if ($fila['estado'] !== 'despacho') {
                throw new PedidoTransicionInvalidaException(
                    "El pedido está en estado '{$fila['estado']}', no en 'despacho'."
                );
            }

            if ($fila['metodo_codigo'] !== 'moto_delivery') {
                throw new PedidoTransicionInvalidaException(
                    'Este pedido no se despacha por motorizado — no hay nada que coordinar.'
                );
            }

            $stmtUpdate = $pdo->prepare('UPDATE pedidos SET motorizado_coordinado = 1 WHERE id = ?');
            $stmtUpdate->execute([$pedidoId]);

            $stmtEvento = $pdo->prepare(
                "INSERT INTO pedido_eventos (pedido_id, usuario_id, estado_anterior, estado_nuevo, nota)
                 VALUES (?, ?, 'despacho', 'despacho', 'Motorizado coordinado')"
            );
            $stmtEvento->execute([$pedidoId, $usuarioQueEjecutaId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function marcarDespachado(int $pedidoId, int $usuarioQueEjecutaId): void
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT p.estado, p.motorizado_coordinado, md.codigo AS metodo_codigo
                 FROM pedidos p
                 LEFT JOIN metodos_despacho md ON md.id = p.metodo_despacho_id
                 WHERE p.id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$pedidoId]);
            $fila = $stmt->fetch();

            if (!$fila) {
                throw new PedidoTransicionInvalidaException("El pedido #{$pedidoId} no existe.");
            }

            if ($fila['estado'] !== 'despacho') {
                throw new PedidoTransicionInvalidaException(
                    "El pedido está en estado '{$fila['estado']}', no en 'despacho'."
                );
            }

            if ($fila['metodo_codigo'] === 'moto_delivery' && (int) $fila['motorizado_coordinado'] === 0) {
                throw new PedidoTransicionInvalidaException(
                    'Coordina el motorizado antes de marcar como entregado.'
                );
            }

            $stmtUpdate = $pdo->prepare(
                "UPDATE pedidos
                 SET estado = 'facturacion_pendiente',
                     despachado_en = NOW(),
                     tiempo_despacho_minutos = TIMESTAMPDIFF(MINUTE, creado_en, NOW())
                 WHERE id = ?"
            );
            $stmtUpdate->execute([$pedidoId]);

            $stmtEvento = $pdo->prepare(
                "INSERT INTO pedido_eventos (pedido_id, usuario_id, estado_anterior, estado_nuevo)
                 VALUES (?, ?, 'despacho', 'facturacion_pendiente')"
            );
            $stmtEvento->execute([$pedidoId, $usuarioQueEjecutaId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // La Cola de Facturación es donde se ve la trazabilidad completa del
    // pedido, por eso trae los 3 responsables (no solo el de despacho).
    public static function listarFacturacionPendiente(): array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->query(
            "SELECT p.*,
                    c.codigo AS canal_codigo, c.nombre AS canal_nombre, c.color_hex AS canal_color,
                    ue.nombre AS resp_embalaje_nombre,
                    uv.nombre AS resp_verificacion_nombre,
                    ud.nombre AS resp_despacho_nombre
             FROM pedidos p
             INNER JOIN canales c ON c.id = p.canal_id
             LEFT JOIN usuarios ue ON ue.id = p.responsable_embalaje_id
             LEFT JOIN usuarios uv ON uv.id = p.responsable_verificacion_id
             LEFT JOIN usuarios ud ON ud.id = p.responsable_despacho_id
             WHERE p.estado = 'facturacion_pendiente'
             ORDER BY p.fecha_limite ASC"
        );
        $filas = $stmt->fetchAll();

        return self::adjuntarItems($pdo, $filas);
    }

    public static function marcarFacturado(int $pedidoId, int $usuarioQueEjecutaId): void
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT estado, requiere_verificar_pago FROM pedidos WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$pedidoId]);
            $fila = $stmt->fetch();

            if (!$fila) {
                throw new PedidoTransicionInvalidaException("El pedido #{$pedidoId} no existe.");
            }

            if ($fila['estado'] !== 'facturacion_pendiente') {
                throw new PedidoTransicionInvalidaException(
                    "El pedido está en estado '{$fila['estado']}', no en 'facturacion_pendiente'."
                );
            }

            $resultado = (int) $fila['requiere_verificar_pago'] === 1 ? 'observacion' : 'exitoso';

            $stmtUpdate = $pdo->prepare(
                "UPDATE pedidos SET estado = 'facturado', facturado_en = NOW(), resultado = ? WHERE id = ?"
            );
            $stmtUpdate->execute([$resultado, $pedidoId]);

            $stmtEvento = $pdo->prepare(
                "INSERT INTO pedido_eventos (pedido_id, usuario_id, estado_anterior, estado_nuevo)
                 VALUES (?, ?, 'facturacion_pendiente', 'facturado')"
            );
            $stmtEvento->execute([$pedidoId, $usuarioQueEjecutaId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // estado => cantidad, para los badges del sidebar (core/ui/layout_header.php).
    public static function contarPorEstado(): array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->query('SELECT estado, COUNT(*) AS cantidad FROM pedidos GROUP BY estado');

        $conteos = [];
        foreach ($stmt->fetchAll() as $fila) {
            $conteos[$fila['estado']] = (int) $fila['cantidad'];
        }

        return $conteos;
    }

    // ------------------------------------------------------------------
    // Panel (index.php): todo de solo lectura, sin transacción.
    // ------------------------------------------------------------------

    public static function obtenerStatsOperativos(): array
    {
        $pdo = Database::getConnection();

        // "Urgente" usa el mismo criterio que el semáforo SLA de
        // pedidos.php: vencido o a menos de 2h del corte.
        $stmt = $pdo->query(
            "SELECT
                SUM(estado = 'nuevo') AS pedidosNuevos,
                SUM(estado = 'embalando') AS enEmbalaje,
                SUM(estado = 'facturacion_pendiente') AS porFacturar,
                SUM(estado IN ('nuevo','embalando') AND fecha_limite < NOW() + INTERVAL 2 HOUR) AS urgentes,
                SUM(estado = 'facturado' AND DATE(facturado_en) = CURDATE()) AS facturadosHoy
             FROM pedidos"
        );
        $fila = $stmt->fetch();

        return [
            'pedidosNuevos' => (int) $fila['pedidosNuevos'],
            'enEmbalaje'    => (int) $fila['enEmbalaje'],
            'porFacturar'   => (int) $fila['porFacturar'],
            'urgentes'      => (int) $fila['urgentes'],
            'facturadosHoy' => (int) $fila['facturadosHoy'],
        ];
    }

    public static function obtenerKpisTrazabilidad(): array
    {
        $pdo = Database::getConnection();

        // YEARWEEK(..., 1): modo 1 = la semana empieza en lunes.
        $stmtVolumen = $pdo->query(
            "SELECT
                SUM(DATE(creado_en) = CURDATE()) AS pedidosHoy,
                SUM(YEARWEEK(creado_en, 1) = YEARWEEK(NOW(), 1)) AS pedidosSemana,
                SUM(YEAR(creado_en) = YEAR(NOW()) AND MONTH(creado_en) = MONTH(NOW())) AS pedidosMes
             FROM pedidos"
        );
        $filaVolumen = $stmtVolumen->fetch();

        $stmtFacturados = $pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(resultado = 'exitoso') AS exitosos,
                SUM(resultado = 'observacion') AS observaciones,
                AVG(tiempo_despacho_minutos) AS tiempoPromedio
             FROM pedidos
             WHERE estado = 'facturado' AND facturado_en >= NOW() - INTERVAL 30 DAY"
        );
        $filaFacturados = $stmtFacturados->fetch();

        $totalFacturados = (int) $filaFacturados['total'];
        $tasaExito = $totalFacturados > 0
            ? (int) round(((int) $filaFacturados['exitosos'] / $totalFacturados) * 100)
            : 0;

        return [
            'pedidosHoy'            => (int) $filaVolumen['pedidosHoy'],
            'pedidosSemana'         => (int) $filaVolumen['pedidosSemana'],
            'pedidosMes'            => (int) $filaVolumen['pedidosMes'],
            'tasaExito'             => $tasaExito,
            'tiempoPromedioMinutos' => $filaFacturados['tiempoPromedio'] !== null
                ? (int) round((float) $filaFacturados['tiempoPromedio'])
                : 0,
            'ocurrencias'           => (int) $filaFacturados['observaciones'],
        ];
    }

    // Siempre devuelve los 5 canales (LEFT JOIN desde `canales`), con
    // cantidad 0 si un canal no tiene pedidos activos — igual que pintaba
    // el prototipo, aunque alguna barra quedara vacía.
    public static function obtenerChartCanal(): array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->query(
            "SELECT c.codigo, c.nombre, c.color_hex AS color, COUNT(p.id) AS cantidad
             FROM canales c
             LEFT JOIN pedidos p ON p.canal_id = c.id AND p.estado NOT IN ('facturado', 'cancelado')
             WHERE c.activo = 1
             GROUP BY c.id, c.codigo, c.nombre, c.color_hex
             ORDER BY c.id"
        );

        return array_map(fn (array $f): array => [
            'codigo'   => $f['codigo'],
            'nombre'   => $f['nombre'],
            'color'    => $f['color'],
            'cantidad' => (int) $f['cantidad'],
        ], $stmt->fetchAll());
    }

    // Mismos umbrales que el semáforo SLA en el JS de pedidos.php
    // (función slaInfo()) — si esos umbrales cambian ahí, hay que
    // cambiarlos aquí también para que Panel y Tablero cuenten igual.
    public static function obtenerChartSla(): array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->query(
            "SELECT
                SUM(fecha_limite < NOW()) AS rojo,
                SUM(fecha_limite >= NOW() AND fecha_limite < NOW() + INTERVAL 2 HOUR) AS amarillo,
                SUM(fecha_limite >= NOW() + INTERVAL 2 HOUR) AS verde
             FROM pedidos
             WHERE estado IN ('nuevo', 'embalando')"
        );
        $fila = $stmt->fetch();

        return [
            ['nivel' => 'verde', 'cantidad' => (int) $fila['verde']],
            ['nivel' => 'amarillo', 'cantidad' => (int) $fila['amarillo']],
            ['nivel' => 'rojo', 'cantidad' => (int) $fila['rojo']],
        ];
    }

    // Siempre devuelve los 4 métodos (LEFT JOIN desde `metodos_despacho`).
    public static function obtenerChartMetodo(): array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->query(
            "SELECT md.codigo, md.nombre, md.color_hex AS color, COUNT(p.id) AS cantidad
             FROM metodos_despacho md
             LEFT JOIN pedidos p ON p.metodo_despacho_id = md.id AND p.creado_en >= NOW() - INTERVAL 30 DAY
             WHERE md.activo = 1
             GROUP BY md.id, md.codigo, md.nombre, md.color_hex
             ORDER BY md.id"
        );

        return array_map(fn (array $f): array => [
            'codigo'   => $f['codigo'],
            'nombre'   => $f['nombre'],
            'color'    => $f['color'],
            'cantidad' => (int) $f['cantidad'],
        ], $stmt->fetchAll());
    }

    // Mismo filtro de ventana que tasaExito/ocurrencias en
    // obtenerKpisTrazabilidad(): facturados en los últimos 30 días.
    public static function obtenerChartResultado(): array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->query(
            "SELECT
                SUM(resultado = 'exitoso') AS exitosos,
                SUM(resultado = 'observacion') AS observaciones
             FROM pedidos
             WHERE estado = 'facturado' AND facturado_en >= NOW() - INTERVAL 30 DAY"
        );
        $fila = $stmt->fetch();

        return [
            ['resultado' => 'exitoso', 'cantidad' => (int) $fila['exitosos']],
            ['resultado' => 'observacion', 'cantidad' => (int) $fila['observaciones']],
        ];
    }

    public static function actualizarEtiquetaPdf(int $pedidoId, string $url): void
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('UPDATE pedidos SET etiqueta_pdf_url = ? WHERE id = ?');
        $stmt->execute([$url, $pedidoId]);
    }

    // Elimina el pedido completo. pedido_items y pedido_eventos tienen
    // ON DELETE CASCADE hacia pedidos.id (ver fk_items_pedido /
    // fk_eventos_pedido), así que borrar la fila de pedidos ya se los
    // lleva; igual se borran items explícito para que el orden quede
    // claro. Devuelve codigo_orden (para que el endpoint pueda borrar el
    // PDF de uploads/etiquetas/ con el mismo saneo que guardarEtiquetaPdf())
    // y etiqueta_pdf_url (null si nunca subió una) — el borrado del
    // archivo físico se hace fuera de la transacción, después del commit.
    public static function eliminar(int $pedidoId): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT codigo_orden, etiqueta_pdf_url FROM pedidos WHERE id = ? FOR UPDATE');
            $stmt->execute([$pedidoId]);
            $fila = $stmt->fetch();

            if ($fila === false) {
                throw new PedidoTransicionInvalidaException("El pedido #{$pedidoId} no existe.");
            }

            $pdo->prepare('DELETE FROM pedido_items WHERE pedido_id = ?')->execute([$pedidoId]);
            $pdo->prepare('DELETE FROM pedidos WHERE id = ?')->execute([$pedidoId]);

            $pdo->commit();

            return [
                'codigo_orden'     => $fila['codigo_orden'],
                'etiqueta_pdf_url' => $fila['etiqueta_pdf_url'],
            ];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
