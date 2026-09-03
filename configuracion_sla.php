<?php
/**
 * configuracion_sla.php
 *
 * CRUD simple de reglas_sla: por canal, 0/1/varias ventanas de corte
 * marketplace -> interna (ver core/pedidos/ReglaSlaCalculator.php). Solo
 * rol 'admin', mismo patrón que usuarios.php / usuarios_guardar.php.
 */

require_once __DIR__ . '/core/bootstrap.php';

Auth::requireRole('admin');

$pdo = Database::getConnection();

// Activar/desactivar directo desde la tabla — una regla desactivada deja
// de aplicarse a pedidos NUEVOS, pero no toca los pedidos ya creados con
// esa regla (fecha_limite ya quedó calculada y grabada en su momento).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'toggle_activo') {
    csrfRequerir();

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($id !== null && $id !== false) {
        $stmt = $pdo->prepare('UPDATE reglas_sla SET activo = NOT activo WHERE id = ?');
        $stmt->execute([$id]);
        error_log("[configuracion_sla.php] Regla id={$id} activada/desactivada por usuario id={$_SESSION['id']}");
    }

    header('Location: ' . baseUrl('configuracion_sla.php') . '?ok=estado');
    exit;
}

$mensajeExito = '';
if (($_GET['ok'] ?? '') === 'creado') {
    $mensajeExito = 'Regla creada correctamente.';
} elseif (($_GET['ok'] ?? '') === 'editado') {
    $mensajeExito = 'Regla actualizada correctamente.';
} elseif (($_GET['ok'] ?? '') === 'estado') {
    $mensajeExito = 'Estado de la regla actualizado.';
}

$errorGuardado = $_GET['error'] ?? '';

$reglaEditar = null;
$idEditar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);
if ($idEditar !== null && $idEditar !== false) {
    $stmt = $pdo->prepare('SELECT id, canal_id, hora_corte_marketplace, hora_corte_interna, activo FROM reglas_sla WHERE id = ? LIMIT 1');
    $stmt->execute([$idEditar]);
    $reglaEditar = $stmt->fetch() ?: null;
}

$canales = $pdo->query('SELECT id, codigo, nombre FROM canales WHERE activo = 1 ORDER BY nombre')->fetchAll();

$reglas = $pdo->query(
    'SELECT r.id, r.canal_id, r.hora_corte_marketplace, r.hora_corte_interna, r.activo,
            c.codigo AS canal_codigo, c.nombre AS canal_nombre
     FROM reglas_sla r
     INNER JOIN canales c ON c.id = r.canal_id
     ORDER BY c.nombre, r.hora_corte_marketplace'
)->fetchAll();

$tituloPagina = 'Reglas SLA';
$navActiva    = 'configuracion_sla';
require __DIR__ . '/core/ui/layout_header.php';
?>
    <style>
        .cabecera-pagina { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .cabecera-pagina h1 { margin: 0; font-size: 22px; }
        .cabecera-pagina p.sub { color: #6b7280; font-size: 13px; margin: 4px 0 0; max-width: 640px; }
        .boton-primario {
            background: #d6483d; color: #fff; border: none; padding: 10px 18px;
            border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; flex-shrink: 0;
        }
        .boton-primario:hover { background: #b83a30; }
        .mensaje-exito {
            background: #eafaf0; border: 1px solid #b7ecc9; color: #166534;
            padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px;
        }
        .mensaje-error {
            background: #fdecec; border: 1px solid #f6b8b3; color: #a3231a;
            padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px;
        }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        th, td { text-align: left; padding: 12px 14px; font-size: 13px; border-bottom: 1px solid #eef0f3; }
        th { background: #fafafa; color: #6b7280; font-weight: 600; text-transform: uppercase; font-size: 11px; letter-spacing: .03em; }
        td.mono { font-variant-numeric: tabular-nums; }
        .badge { padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .badge--activo { background: #eafaf0; color: #166534; }
        .badge--inactivo { background: #f3f4f6; color: #6b7280; }
        .acciones a, .acciones button {
            font-size: 12px; font-weight: 600; text-decoration: none; border: none; background: none; cursor: pointer; padding: 0; margin-right: 12px;
        }
        .acciones a { color: #2563eb; }
        .acciones button { color: #a3231a; }
        .empty { text-align: center; color: #6b7280; padding: 22px 10px; font-size: 13px; }

        .modal-fondo { display: none; position: fixed; inset: 0; background: rgba(22,28,43,0.55); align-items: center; justify-content: center; z-index: 50; }
        .modal-fondo.abierto { display: flex; }
        .modal-caja { background: #fff; width: 100%; max-width: 420px; border-radius: 12px; padding: 28px; }
        .modal-caja h2 { margin: 0 0 18px; font-size: 18px; }
        .modal-caja label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .modal-caja input, .modal-caja select {
            width: 100%; padding: 9px 10px; margin-bottom: 14px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit; box-sizing: border-box;
        }
        .modal-ayuda { font-size: 12px; color: #6b7280; margin: -8px 0 14px; }
        .modal-botones { display: flex; gap: 10px; justify-content: flex-end; margin-top: 6px; }
        .boton-secundario { background: #f3f4f6; color: #374151; border: none; padding: 10px 16px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
    </style>

    <div class="cabecera-pagina">
        <div>
            <h1>Reglas SLA</h1>
            <p class="sub">Colchón de seguridad interno de Almacén antes del corte real de cada marketplace. Un canal puede tener 0, 1 o varias ventanas de corte.</p>
        </div>
        <button type="button" class="boton-primario" onclick="abrirModalNuevo()">+ Nueva regla</button>
    </div>

    <?php if ($mensajeExito !== ''): ?>
        <div class="mensaje-exito"><?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($errorGuardado !== ''): ?>
        <div class="mensaje-error"><?= htmlspecialchars($errorGuardado, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Canal</th>
                <th>Corte marketplace</th>
                <th>Corte interno (Almacén)</th>
                <th>Colchón</th>
                <th>Estado</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($reglas)): ?>
                <tr><td colspan="6" class="empty">Todavía no hay reglas configuradas.</td></tr>
            <?php endif; ?>
            <?php foreach ($reglas as $r): ?>
                <?php
                    $minMarket = (int) substr($r['hora_corte_marketplace'], 0, 2) * 60 + (int) substr($r['hora_corte_marketplace'], 3, 2);
                    $minInterna = (int) substr($r['hora_corte_interna'], 0, 2) * 60 + (int) substr($r['hora_corte_interna'], 3, 2);
                    $colchon = $minMarket - $minInterna;
                ?>
                <tr>
                    <td><?= htmlspecialchars($r['canal_nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="mono"><?= substr(htmlspecialchars($r['hora_corte_marketplace'], ENT_QUOTES, 'UTF-8'), 0, 5) ?></td>
                    <td class="mono"><?= substr(htmlspecialchars($r['hora_corte_interna'], ENT_QUOTES, 'UTF-8'), 0, 5) ?></td>
                    <td class="mono"><?= $colchon > 0 ? $colchon . ' min' : '⚠️ inválido' ?></td>
                    <td>
                        <?php if ((int) $r['activo'] === 1): ?>
                            <span class="badge badge--activo">Activa</span>
                        <?php else: ?>
                            <span class="badge badge--inactivo">Inactiva</span>
                        <?php endif; ?>
                    </td>
                    <td class="acciones">
                        <a href="<?= htmlspecialchars(baseUrl('configuracion_sla.php') . '?editar=' . (int) $r['id'], ENT_QUOTES, 'UTF-8') ?>">Editar</a>
                        <form method="post" action="<?= htmlspecialchars(baseUrl('configuracion_sla.php'), ENT_QUOTES, 'UTF-8') ?>" style="display:inline;">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="accion" value="toggle_activo">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button type="submit"><?= (int) $r['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="modal-fondo <?= $reglaEditar !== null ? 'abierto' : '' ?>" id="modalRegla">
        <div class="modal-caja">
            <h2 id="modalTitulo"><?= $reglaEditar !== null ? 'Editar regla' : 'Nueva regla' ?></h2>
            <form method="post" action="<?= htmlspecialchars(baseUrl('configuracion_sla_guardar.php'), ENT_QUOTES, 'UTF-8') ?>">
                <?= csrfCampo() ?>
                <input type="hidden" name="id" value="<?= $reglaEditar['id'] ?? '' ?>">

                <label for="canal_id">Canal</label>
                <select id="canal_id" name="canal_id" required>
                    <option value="">— Selecciona —</option>
                    <?php foreach ($canales as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= isset($reglaEditar['canal_id']) && (int) $reglaEditar['canal_id'] === (int) $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['nombre'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="hora_corte_marketplace">Hora de corte real del marketplace</label>
                <input type="time" id="hora_corte_marketplace" name="hora_corte_marketplace" required
                    value="<?= isset($reglaEditar['hora_corte_marketplace']) ? substr($reglaEditar['hora_corte_marketplace'], 0, 5) : '' ?>">

                <label for="hora_corte_interna">Hora de corte interna (Almacén)</label>
                <input type="time" id="hora_corte_interna" name="hora_corte_interna" required
                    value="<?= isset($reglaEditar['hora_corte_interna']) ? substr($reglaEditar['hora_corte_interna'], 0, 5) : '' ?>">
                <p class="modal-ayuda">Debe ser anterior a la hora marketplace — la diferencia es el colchón de seguridad.</p>

                <div class="modal-botones">
                    <button type="button" class="boton-secundario" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="boton-primario"><?= $reglaEditar !== null ? 'Guardar cambios' : 'Crear regla' ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModalNuevo() {
            document.getElementById('modalRegla').classList.add('abierto');
        }
        function cerrarModal() {
            document.getElementById('modalRegla').classList.remove('abierto');
            window.location.href = '<?= htmlspecialchars(baseUrl('configuracion_sla.php'), ENT_QUOTES, 'UTF-8') ?>';
        }
    </script>
<?php
require __DIR__ . '/core/ui/layout_footer.php';
