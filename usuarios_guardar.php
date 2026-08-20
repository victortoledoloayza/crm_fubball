<?php
/**
 * usuarios_guardar.php
 *
 * Procesa el alta/edición de usuarios enviada desde usuarios.php. Solo
 * rol 'admin'.
 */

require_once __DIR__ . '/core/bootstrap.php';

$usuarioSesion = Auth::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . baseUrl('usuarios.php'));
    exit;
}

csrfRequerir();

$pdo = Database::getConnection();

$id       = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$nombre   = trim($_POST['nombre'] ?? '');
$usuario  = trim($_POST['usuario'] ?? '');
$email    = trim($_POST['email'] ?? '');
$password = (string) ($_POST['password'] ?? '');
$rol      = trim($_POST['rol'] ?? '');

$esEdicion = $id !== null && $id !== false && $id > 0;

if ($nombre === '' || $usuario === '' || $rol === '' || (!$esEdicion && $password === '')) {
    header('Location: ' . baseUrl('usuarios.php') . '?error=' . urlencode('Completa todos los campos obligatorios.'));
    exit;
}

try {
    if ($esEdicion) {
        if ($password !== '') {
            $stmt = $pdo->prepare(
                'UPDATE usuarios SET nombre = ?, usuario = ?, email = ?, rol = ?, password_hash = ? WHERE id = ?'
            );
            $stmt->execute([$nombre, $usuario, $email, $rol, password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE usuarios SET nombre = ?, usuario = ?, email = ?, rol = ? WHERE id = ?'
            );
            $stmt->execute([$nombre, $usuario, $email, $rol, $id]);
        }

        error_log("[usuarios_guardar.php] Usuario id={$id} editado por usuario id={$usuarioSesion['id']}");
        header('Location: ' . baseUrl('usuarios.php') . '?ok=editado');
        exit;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO usuarios (nombre, usuario, email, password_hash, rol, activo, intentos_fallidos)
         VALUES (?, ?, ?, ?, ?, 1, 0)'
    );
    $stmt->execute([$nombre, $usuario, $email, password_hash($password, PASSWORD_DEFAULT), $rol]);

    $nuevoId = $pdo->lastInsertId();
    error_log("[usuarios_guardar.php] Usuario id={$nuevoId} creado por usuario id={$usuarioSesion['id']}");
    header('Location: ' . baseUrl('usuarios.php') . '?ok=creado');
    exit;
} catch (PDOException $e) {
    // Código 1062 = entrada duplicada (constraint UNIQUE sobre `usuario`).
    if ((int) $e->errorInfo[1] === 1062) {
        $mensaje = 'Ese nombre de usuario ya existe. Elige otro.';
    } else {
        error_log('[usuarios_guardar.php] Error al guardar usuario: ' . $e->getMessage());
        $mensaje = 'Ocurrió un error al guardar el usuario.';
    }

    header('Location: ' . baseUrl('usuarios.php') . '?error=' . urlencode($mensaje));
    exit;
}
