<?php
/**
 * usuarios.php
 *
 * Listado + alta/edición de usuarios. Solo rol 'admin'.
 */

require_once __DIR__ . '/core/bootstrap.php';

Auth::requireRole('admin');

$pdo = Database::getConnection();

// Activar/desactivar directo desde la tabla (no cambia contraseña ni rol).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'toggle_activo') {
    csrfRequerir();

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($id !== null && $id !== false) {
        $stmt = $pdo->prepare('UPDATE usuarios SET activo = NOT activo WHERE id = ?');
        $stmt->execute([$id]);
        error_log("[usuarios.php] Usuario id={$id} activado/desactivado por usuario id={$_SESSION['id']}");
    }

    header('Location: ' . baseUrl('usuarios.php') . '?ok=estado');
    exit;
}

$mensajeExito = '';
if (($_GET['ok'] ?? '') === 'creado') {
    $mensajeExito = 'Usuario creado correctamente.';
} elseif (($_GET['ok'] ?? '') === 'editado') {
    $mensajeExito = 'Usuario actualizado correctamente.';
} elseif (($_GET['ok'] ?? '') === 'estado') {
    $mensajeExito = 'Estado del usuario actualizado.';
}

$errorGuardado = $_GET['error'] ?? '';

$usuarioEditar = null;
$idEditar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);
if ($idEditar !== null && $idEditar !== false) {
    $stmt = $pdo->prepare('SELECT id, nombre, usuario, email, rol, activo FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$idEditar]);
    $usuarioEditar = $stmt->fetch() ?: null;
}

$usuarios = $pdo->query(
    'SELECT id, nombre, usuario, email, rol, activo, ultimo_acceso FROM usuarios ORDER BY nombre'
)->fetchAll();

$tituloPagina = 'Usuarios';
$navActiva    = 'usuarios';
require __DIR__ . '/core/ui/layout_header.php';
?>
    <style>
        .cabecera-pagina { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .cabecera-pagina h1 { margin: 0; font-size: 22px; }
        .boton-primario {
            background: #d6483d; color: #fff; border: none; padding: 10px 18px;
            border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none;
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
        .badge { padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .badge--activo { background: #eafaf0; color: #166534; }
        .badge--inactivo { background: #f3f4f6; color: #6b7280; }
        .acciones a, .acciones button {
            font-size: 12px; font-weight: 600; text-decoration: none; border: none; background: none; cursor: pointer; padding: 0; margin-right: 12px;
        }
        .acciones a { color: #2563eb; }
        .acciones button { color: #a3231a; }

        .modal-fondo { display: none; position: fixed; inset: 0; background: rgba(22,28,43,0.55); align-items: center; justify-content: center; z-index: 50; }
        .modal-fondo.abierto { display: flex; }
        .modal-caja { background: #fff; width: 100%; max-width: 420px; border-radius: 12px; padding: 28px; }
        .modal-caja h2 { margin: 0 0 18px; font-size: 18px; }
        .modal-caja label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .modal-caja input, .modal-caja select {
            width: 100%; padding: 9px 10px; margin-bottom: 14px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit;
        }
        .modal-botones { display: flex; gap: 10px; justify-content: flex-end; margin-top: 6px; }
        .boton-secundario { background: #f3f4f6; color: #374151; border: none; padding: 10px 16px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
    </style>

    <div class="cabecera-pagina">
        <h1>Usuarios</h1>
        <button type="button" class="boton-primario" onclick="abrirModalNuevo()">+ Nuevo usuario</button>
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
                <th>Nombre</th>
                <th>Usuario</th>
                <th>Email</th>
                <th>Rol</th>
                <th>Activo</th>
                <th>Último acceso</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($u['usuario'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($u['rol'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if ((int) $u['activo'] === 1): ?>
                            <span class="badge badge--activo">Activo</span>
                        <?php else: ?>
                            <span class="badge badge--inactivo">Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $u['ultimo_acceso'] ? htmlspecialchars($u['ultimo_acceso'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
                    <td class="acciones">
                        <a href="<?= htmlspecialchars(baseUrl('usuarios.php') . '?editar=' . (int) $u['id'], ENT_QUOTES, 'UTF-8') ?>">Editar</a>
                        <form method="post" action="<?= htmlspecialchars(baseUrl('usuarios.php'), ENT_QUOTES, 'UTF-8') ?>" style="display:inline;">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="accion" value="toggle_activo">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <button type="submit"><?= (int) $u['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="modal-fondo <?= $usuarioEditar !== null ? 'abierto' : '' ?>" id="modalUsuario">
        <div class="modal-caja">
            <h2 id="modalTitulo"><?= $usuarioEditar !== null ? 'Editar usuario' : 'Nuevo usuario' ?></h2>
            <form method="post" action="<?= htmlspecialchars(baseUrl('usuarios_guardar.php'), ENT_QUOTES, 'UTF-8') ?>">
                <?= csrfCampo() ?>
                <input type="hidden" name="id" value="<?= $usuarioEditar['id'] ?? '' ?>">

                <label for="nombre">Nombre</label>
                <input type="text" id="nombre" name="nombre" required value="<?= htmlspecialchars($usuarioEditar['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                <label for="usuario">Usuario</label>
                <input type="text" id="usuario" name="usuario" required value="<?= htmlspecialchars($usuarioEditar['usuario'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                <label for="email">Email</label>
                <input type="text" id="email" name="email" value="<?= htmlspecialchars($usuarioEditar['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                <label for="password">Contraseña <?= $usuarioEditar !== null ? '(déjalo vacío para no cambiarla)' : '' ?></label>
                <input type="password" id="password" name="password" autocomplete="new-password" <?= $usuarioEditar === null ? 'required' : '' ?>>

                <label for="rol">Rol</label>
                <select id="rol" name="rol" required>
                    <?php $rolActual = $usuarioEditar['rol'] ?? ''; ?>
                    <option value="admin" <?= $rolActual === 'admin' ? 'selected' : '' ?>>Administrador</option>
                    <option value="almacen" <?= $rolActual === 'almacen' ? 'selected' : '' ?>>Almacén</option>
                    <option value="despacho" <?= $rolActual === 'despacho' ? 'selected' : '' ?>>Despacho</option>
                    <option value="ventas" <?= $rolActual === 'ventas' ? 'selected' : '' ?>>Ventas</option>
                </select>

                <div class="modal-botones">
                    <button type="button" class="boton-secundario" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="boton-primario"><?= $usuarioEditar !== null ? 'Guardar cambios' : 'Crear usuario' ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function abrirModalNuevo() {
            document.getElementById('modalUsuario').classList.add('abierto');
        }
        function cerrarModal() {
            document.getElementById('modalUsuario').classList.remove('abierto');
            window.location.href = '<?= htmlspecialchars(baseUrl('usuarios.php'), ENT_QUOTES, 'UTF-8') ?>';
        }
    </script>
<?php
require __DIR__ . '/core/ui/layout_footer.php';
