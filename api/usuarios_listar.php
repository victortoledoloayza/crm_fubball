<?php
/**
 * api/usuarios_listar.php (GET)
 *
 * ?activos=1 — lista simple {id, nombre} de usuarios, para llenar el
 * select de responsable de cada tarjeta del Tablero KDS. Cualquier
 * usuario logueado puede consultarla (no hace falta ser admin).
 */

require_once __DIR__ . '/../core/bootstrap.php';

Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();

$soloActivos = ($_GET['activos'] ?? '') === '1';

$sql = 'SELECT id, nombre FROM usuarios';
if ($soloActivos) {
    $sql .= ' WHERE activo = 1';
}
$sql .= ' ORDER BY nombre';

$usuarios = $pdo->query($sql)->fetchAll();

echo json_encode(['ok' => true, 'usuarios' => $usuarios]);
