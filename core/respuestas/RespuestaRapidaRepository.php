<?php
/**
 * core/respuestas/RespuestaRapidaRepository.php
 *
 * Acceso a datos de `respuestas_rapidas` / `respuestas_rapidas_adjuntos`.
 *
 * respuestas_rapidas.canal_id es NULL cuando la respuesta aplica a
 * cualquier canal, o el id de `canales` cuando es específica de uno
 * (mismo patrón que pedidos.canal_id). respuestas_rapidas_adjuntos tiene
 * ON DELETE CASCADE hacia respuestas_rapidas.id — borrar la respuesta se
 * lleva sus filas de adjuntos, pero los archivos físicos en
 * uploads/respuestas_rapidas/ hay que borrarlos aparte (ver
 * core/util/RespuestaAdjunto.php) después del commit.
 */

class RespuestaRapidaNoEncontradaException extends RuntimeException
{
}

class RespuestaRapidaRepository
{
    // Todas (activas e inactivas), para el panel admin. $soloActivas=true
    // es lo que consume el endpoint de token (extensión).
    public static function listar(bool $soloActivas = false): array
    {
        $pdo = Database::getConnection();

        $sql = "SELECT r.*, c.codigo AS canal_codigo, c.nombre AS canal_nombre
                FROM respuestas_rapidas r
                LEFT JOIN canales c ON c.id = r.canal_id";
        if ($soloActivas) {
            $sql .= ' WHERE r.activo = 1';
        }
        $sql .= ' ORDER BY r.orden ASC, r.id ASC';

        $filas = $pdo->query($sql)->fetchAll();

        return self::adjuntarAdjuntos($pdo, $filas);
    }

    public static function obtener(int $id): ?array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'SELECT r.*, c.codigo AS canal_codigo, c.nombre AS canal_nombre
             FROM respuestas_rapidas r
             LEFT JOIN canales c ON c.id = r.canal_id
             WHERE r.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $fila = $stmt->fetch();

        if (!$fila) {
            return null;
        }

        $respuestas = self::adjuntarAdjuntos($pdo, [$fila]);

        return $respuestas[0];
    }

    // Trae los adjuntos de todas las respuestas ya obtenidas en una sola
    // query, evitando el problema N+1 (mismo patrón que
    // PedidoRepository::adjuntarItems()).
    private static function adjuntarAdjuntos(PDO $pdo, array $filasRespuestas): array
    {
        if (empty($filasRespuestas)) {
            return [];
        }

        $ids = array_map(fn (array $r): int => (int) $r['id'], $filasRespuestas);
        $marcadores = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare(
            "SELECT * FROM respuestas_rapidas_adjuntos WHERE respuesta_id IN ({$marcadores}) ORDER BY id"
        );
        $stmt->execute($ids);

        $adjuntosPorRespuesta = [];
        foreach ($stmt->fetchAll() as $adjunto) {
            $adjuntosPorRespuesta[(int) $adjunto['respuesta_id']][] = $adjunto;
        }

        return array_map(function (array $r) use ($adjuntosPorRespuesta): array {
            $r['adjuntos'] = $adjuntosPorRespuesta[(int) $r['id']] ?? [];

            return $r;
        }, $filasRespuestas);
    }

    // $datos: titulo, texto, canal_id (puede ser NULL), orden, activo,
    // creado_por (puede ser NULL: la extensión no siempre tiene un
    // usuario logueado detrás).
    public static function crear(array $datos): int
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'INSERT INTO respuestas_rapidas (titulo, texto, canal_id, orden, activo, creado_por)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $datos['titulo'],
            $datos['texto'],
            $datos['canal_id'] ?: null,
            $datos['orden'] ?? 0,
            $datos['activo'] ? 1 : 0,
            $datos['creado_por'] ?: null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function editar(int $id, array $datos): void
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'UPDATE respuestas_rapidas
             SET titulo = ?, texto = ?, canal_id = ?, orden = ?, activo = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $datos['titulo'],
            $datos['texto'],
            $datos['canal_id'] ?: null,
            $datos['orden'] ?? 0,
            $datos['activo'] ? 1 : 0,
            $id,
        ]);

        if ($stmt->rowCount() === 0 && self::obtener($id) === null) {
            throw new RespuestaRapidaNoEncontradaException("La respuesta #{$id} no existe.");
        }
    }

    // Borra la respuesta (los adjuntos se van en cascada por FK) y
    // devuelve sus paths de archivo, para que el endpoint los borre del
    // disco después del commit.
    public static function eliminar(int $id): array
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT id FROM respuestas_rapidas WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() === false) {
                throw new RespuestaRapidaNoEncontradaException("La respuesta #{$id} no existe.");
            }

            $stmtAdjuntos = $pdo->prepare('SELECT path FROM respuestas_rapidas_adjuntos WHERE respuesta_id = ?');
            $stmtAdjuntos->execute([$id]);
            $paths = $stmtAdjuntos->fetchAll(PDO::FETCH_COLUMN);

            $pdo->prepare('DELETE FROM respuestas_rapidas WHERE id = ?')->execute([$id]);

            $pdo->commit();

            return $paths;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // $adjunto: tipo, path, nombre_archivo, mime_type (ver
    // core/util/RespuestaAdjunto.php).
    public static function agregarAdjunto(int $respuestaId, array $adjunto): int
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare(
            'INSERT INTO respuestas_rapidas_adjuntos (respuesta_id, tipo, path, nombre_archivo, mime_type)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $respuestaId,
            $adjunto['tipo'],
            $adjunto['path'],
            $adjunto['nombre_archivo'],
            $adjunto['mime_type'],
        ]);

        return (int) $pdo->lastInsertId();
    }

    // Borra la fila del adjunto y devuelve su path (o null si no existía),
    // para que el endpoint borre el archivo del disco después.
    public static function eliminarAdjunto(int $adjuntoId): ?string
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('SELECT path FROM respuestas_rapidas_adjuntos WHERE id = ?');
        $stmt->execute([$adjuntoId]);
        $path = $stmt->fetchColumn();

        if ($path === false) {
            return null;
        }

        $pdo->prepare('DELETE FROM respuestas_rapidas_adjuntos WHERE id = ?')->execute([$adjuntoId]);

        return $path;
    }
}
