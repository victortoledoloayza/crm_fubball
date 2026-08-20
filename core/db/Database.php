<?php
/**
 * core/db/Database.php
 *
 * Conexión PDO a MySQL, patrón singleton. Todas las queries del proyecto
 * (aquí y en fases futuras) deben usar prepared statements — nunca
 * concatenar valores directo en el SQL.
 */

class Database
{
    private static ?PDO $conexion = null;

    public static function getConnection(): PDO
    {
        if (self::$conexion === null) {
            $host    = env('DB_HOST', '127.0.0.1');
            $puerto  = env('DB_PORT', '3306');
            $nombre  = env('DB_NAME');
            $usuario = env('DB_USER');
            $clave   = env('DB_PASS');

            try {
                self::$conexion = new PDO(
                    "mysql:host={$host};port={$puerto};dbname={$nombre};charset=utf8mb4",
                    $usuario,
                    $clave,
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]
                );
            } catch (PDOException $e) {
                error_log('[Database] Error de conexión: ' . $e->getMessage());
                http_response_code(500);
                die('Error de conexión a la base de datos. Revisa las credenciales en .env o los logs del servidor.');
            }
        }

        return self::$conexion;
    }
}
