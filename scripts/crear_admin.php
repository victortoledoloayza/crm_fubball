<?php
/**
 * scripts/crear_admin.php
 *
 * Script de línea de comandos para crear el PRIMER usuario admin. Es la
 * única forma soportada de crear ese primer admin (no hay seed SQL con
 * credenciales hardcodeadas).
 *
 * Uso:
 *   php scripts/crear_admin.php
 */

if (PHP_SAPI !== 'cli') {
    die("Este script solo puede ejecutarse por línea de comandos.\n");
}

require_once __DIR__ . '/../core/util/env.php';

loadEnv(__DIR__ . '/../.env');

require_once __DIR__ . '/../core/db/Database.php';

function leerLinea(string $prompt): string
{
    echo $prompt;
    return trim((string) fgets(STDIN));
}

function leerPassword(string $prompt): string
{
    echo $prompt;
    // -s oculta la entrada en terminales tipo Unix; en Windows queda visible.
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        system('stty -echo 2>/dev/null');
        $password = trim((string) fgets(STDIN));
        system('stty echo 2>/dev/null');
        echo "\n";
        return $password;
    }

    return trim((string) fgets(STDIN));
}

echo "== Crear primer usuario admin — Fubball KDS ==\n\n";

$nombre = leerLinea('Nombre completo: ');
if ($nombre === '') {
    die("El nombre no puede estar vacío.\n");
}

$usuario = leerLinea('Usuario (login): ');
if ($usuario === '' || !preg_match('/^[a-zA-Z0-9_.]+$/', $usuario)) {
    die("El usuario debe tener al menos un caracter y solo letras, números, '_' o '.'.\n");
}

$email = leerLinea('Email: ');
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Email inválido.\n");
}

$password = leerPassword('Contraseña (mínimo 8 caracteres): ');
if (strlen($password) < 8) {
    die("La contraseña debe tener al menos 8 caracteres.\n");
}

$passwordConfirmar = leerPassword('Confirma la contraseña: ');
if ($password !== $passwordConfirmar) {
    die("Las contraseñas no coinciden.\n");
}

$pdo = Database::getConnection();

$stmtExiste = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = ? LIMIT 1');
$stmtExiste->execute([$usuario]);
if ($stmtExiste->fetch()) {
    die("Ya existe un usuario con ese login ('{$usuario}').\n");
}

$stmt = $pdo->prepare(
    'INSERT INTO usuarios (nombre, usuario, email, password_hash, rol, activo, intentos_fallidos)
     VALUES (?, ?, ?, ?, \'admin\', 1, 0)'
);
$stmt->execute([$nombre, $usuario, $email, password_hash($password, PASSWORD_DEFAULT)]);

echo "\nUsuario admin '{$usuario}' creado correctamente (id " . $pdo->lastInsertId() . ").\n";
