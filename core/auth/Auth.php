<?php
/**
 * core/auth/Auth.php
 *
 * Login, logout, sesión y verificación de rol contra la tabla `usuarios`.
 *
 * Columnas esperadas en `usuarios` (ver fubball_kds_schema.sql):
 *   id, nombre, usuario, email, password_hash, rol, activo,
 *   intentos_fallidos, bloqueado_hasta, ultimo_acceso
 *
 * password_hash($password, PASSWORD_DEFAULT) se usa en cualquier lugar
 * donde se guarda una contraseña nueva (alta/edición de usuario) — nunca
 * aquí, que solo verifica.
 */

class Auth
{
    private const MAX_INTENTOS_FALLIDOS = 5;
    private const MINUTOS_BLOQUEO = 15;

    // Devuelve false tanto si el usuario no existe, está inactivo, está
    // bloqueado o la contraseña no coincide — nunca revela cuál de esos
    // casos ocurrió, para no filtrar si un usuario existe o no.
    public static function login(string $usuario, string $password): bool
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE usuario = ? LIMIT 1');
        $stmt->execute([$usuario]);
        $fila = $stmt->fetch();

        if (!$fila) {
            return false;
        }

        if ((int) $fila['activo'] !== 1) {
            return false;
        }

        if (!empty($fila['bloqueado_hasta']) && strtotime($fila['bloqueado_hasta']) > time()) {
            return false;
        }

        if (!password_verify($password, $fila['password_hash'])) {
            self::registrarIntentoFallido($pdo, (int) $fila['id'], (int) $fila['intentos_fallidos']);

            return false;
        }

        $stmtActualizar = $pdo->prepare(
            'UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_acceso = NOW() WHERE id = ?'
        );
        $stmtActualizar->execute([$fila['id']]);

        self::iniciarSesionPhp();
        session_regenerate_id(true);

        $_SESSION['id']      = (int) $fila['id'];
        $_SESSION['nombre']  = $fila['nombre'];
        $_SESSION['usuario'] = $fila['usuario'];
        $_SESSION['rol']     = $fila['rol'];

        return true;
    }

    private static function registrarIntentoFallido(PDO $pdo, int $id, int $intentosActuales): void
    {
        $intentos = $intentosActuales + 1;

        if ($intentos >= self::MAX_INTENTOS_FALLIDOS) {
            $stmt = $pdo->prepare(
                'UPDATE usuarios
                 SET intentos_fallidos = ?, bloqueado_hasta = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                 WHERE id = ?'
            );
            $stmt->execute([$intentos, self::MINUTOS_BLOQUEO, $id]);

            return;
        }

        $stmt = $pdo->prepare('UPDATE usuarios SET intentos_fallidos = ? WHERE id = ?');
        $stmt->execute([$intentos, $id]);
    }

    public static function logout(): void
    {
        self::iniciarSesionPhp();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $parametros = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $parametros['path'],
                $parametros['domain'],
                $parametros['secure'],
                $parametros['httponly']
            );
        }

        session_destroy();
    }

    public static function currentUser(): ?array
    {
        self::iniciarSesionPhp();

        if (empty($_SESSION['id'])) {
            return null;
        }

        return [
            'id'      => $_SESSION['id'],
            'nombre'  => $_SESSION['nombre'],
            'usuario' => $_SESSION['usuario'],
            'rol'     => $_SESSION['rol'],
        ];
    }

    // Debe llamarse al inicio de TODA página protegida.
    public static function requireLogin(): array
    {
        $usuario = self::currentUser();

        if ($usuario === null) {
            header('Location: ' . baseUrl('login.php'));
            exit;
        }

        return $usuario;
    }

    // requireLogin() primero; si el rol no está permitido, responde 403 sin
    // romper con un error PHP crudo.
    public static function requireRole(string ...$rolesPermitidos): array
    {
        $usuario = self::requireLogin();

        if (!in_array($usuario['rol'], $rolesPermitidos, true)) {
            http_response_code(403);
            echo 'No tienes permiso para acceder a esta sección.';
            exit;
        }

        return $usuario;
    }

    private static function iniciarSesionPhp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
