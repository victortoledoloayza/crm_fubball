<?php
/**
 * configuracion_sla_guardar.php
 *
 * Procesa el alta/edición de reglas_sla enviada desde configuracion_sla.php.
 * Solo rol 'admin'.
 */

require_once __DIR__ . '/core/bootstrap.php';

$usuarioSesion = Auth::requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . baseUrl('configuracion_sla.php'));
    exit;
}

csrfRequerir();

$pdo = Database::getConnection();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$canalId = filter_input(INPUT_POST, 'canal_id', FILTER_VALIDATE_INT);
$horaMarketplace = trim($_POST['hora_corte_marketplace'] ?? '');
$horaInterna = trim($_POST['hora_corte_interna'] ?? '');

$esEdicion = $id !== null && $id !== false && $id > 0;

if (!$canalId || $horaMarketplace === '' || $horaInterna === '') {
    header('Location: ' . baseUrl('configuracion_sla.php') . '?error=' . urlencode('Completa todos los campos obligatorios.'));
    exit;
}

// <input type="time"> manda "HH:MM"; la columna es TIME, "HH:MM:00" es
// válido pero se completa el segundo explícito para que quede consistente
// con lo que ya guarda cualquier otro insert manual futuro.
$horaMarketplace .= ':00';
$horaInterna .= ':00';

// La interna tiene que ser estrictamente anterior a la marketplace — si
// no, el "colchón" sería cero o negativo y ReglaSlaCalculator la
// ignoraría en silencio (con un error_log) en vez de aplicarla. Mejor
// cortarlo acá, en el formulario, con un mensaje claro.
if ($horaInterna >= $horaMarketplace) {
    header('Location: ' . baseUrl('configuracion_sla.php') . '?error=' . urlencode('La hora de corte interna debe ser anterior a la hora de corte del marketplace.'));
    exit;
}

try {
    if ($esEdicion) {
        $stmt = $pdo->prepare(
            'UPDATE reglas_sla SET canal_id = ?, hora_corte_marketplace = ?, hora_corte_interna = ? WHERE id = ?'
        );
        $stmt->execute([$canalId, $horaMarketplace, $horaInterna, $id]);

        error_log("[configuracion_sla_guardar.php] Regla id={$id} editada por usuario id={$usuarioSesion['id']}");
        header('Location: ' . baseUrl('configuracion_sla.php') . '?ok=editado');
        exit;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO reglas_sla (canal_id, hora_corte_marketplace, hora_corte_interna, activo) VALUES (?, ?, ?, 1)'
    );
    $stmt->execute([$canalId, $horaMarketplace, $horaInterna]);

    $nuevoId = $pdo->lastInsertId();
    error_log("[configuracion_sla_guardar.php] Regla id={$nuevoId} creada por usuario id={$usuarioSesion['id']}");
    header('Location: ' . baseUrl('configuracion_sla.php') . '?ok=creado');
    exit;
} catch (PDOException $e) {
    error_log('[configuracion_sla_guardar.php] Error al guardar regla: ' . $e->getMessage());
    header('Location: ' . baseUrl('configuracion_sla.php') . '?error=' . urlencode('Ocurrió un error al guardar la regla.'));
    exit;
}
