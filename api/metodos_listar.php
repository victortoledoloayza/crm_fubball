<?php
/**
 * api/metodos_listar.php (GET)
 *
 * Lista simple {id, codigo, nombre} de métodos de despacho activos —
 * la usa pedidos.php para el select de "método pendiente de confirmar"
 * en la columna Verificación (pedidos que llegaron sin método por
 * Shopify/extensión Chrome).
 */

require_once __DIR__ . '/../core/bootstrap.php';

Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();

$metodos = $pdo->query('SELECT id, codigo, nombre FROM metodos_despacho WHERE activo = 1 ORDER BY nombre')->fetchAll();

echo json_encode(['ok' => true, 'metodos' => $metodos]);
