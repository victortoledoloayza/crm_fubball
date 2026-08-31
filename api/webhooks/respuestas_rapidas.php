<?php
/**
 * api/webhooks/respuestas_rapidas.php (token API, ver core/auth/ApiToken.php)
 *
 * Endpoint hermano de los api/respuestas_rapidas_*.php de sesión, pero
 * para la extensión de Chrome — mismo criterio de auth que
 * api/webhooks/extension_pedido.php (Authorization: Bearer ..., sin
 * sesión ni CSRF).
 *
 * GET: lista las respuestas activas con sus adjuntos, con URL pública
 *   directa a cada archivo (uploads/respuestas_rapidas/... ya se sirve
 *   estático — ver .htaccess de esa carpeta) para que la extensión no
 *   necesite un paso de descarga aparte.
 *
 * POST: crear/editar/borrar, seleccionado por el campo `accion`:
 *   - 'guardar' (default si no viene `accion`): { id? (edición si viene),
 *     titulo, texto, canal_id?, orden?, activo? }. Si se manda como
 *     multipart/form-data puede incluir archivos en adjuntos[] (mismo
 *     límite y validación que el panel admin, ver
 *     core/util/RespuestaAdjunto.php); si se manda como JSON no hay forma
 *     de subir archivos en la misma llamada.
 *   - 'eliminar': { id } — borra la respuesta completa.
 *   - 'adjunto_eliminar': { adjunto_id } — borra un adjunto suelto.
 */

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/auth/ApiToken.php';
require_once __DIR__ . '/../../core/respuestas/RespuestaRapidaRepository.php';
require_once __DIR__ . '/../../core/util/RespuestaAdjunto.php';

$tokenFila = requireApiToken();

header('Content-Type: application/json; charset=utf-8');

$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'GET') {
    $respuestas = RespuestaRapidaRepository::listar(true);

    foreach ($respuestas as &$respuesta) {
        foreach ($respuesta['adjuntos'] as &$adjunto) {
            $adjunto['url'] = baseUrl($adjunto['path']);
        }
        unset($adjunto);
    }
    unset($respuesta);

    echo json_encode(['ok' => true, 'respuestas' => $respuestas]);
    exit;
}

if ($metodo !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no soportado.']);
    exit;
}

$esMultipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
$body = $esMultipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?? []);
$accion = $body['accion'] ?? 'guardar';

$pdo = Database::getConnection();

if ($accion === 'eliminar') {
    $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta id.']);
        exit;
    }

    try {
        $paths = RespuestaRapidaRepository::eliminar($id);
        foreach ($paths as $path) {
            eliminarArchivoAdjuntoRespuesta($path);
        }

        error_log("[webhooks/respuestas_rapidas.php] Respuesta id={$id} eliminada vía token id={$tokenFila['id']}");
        echo json_encode(['ok' => true]);
    } catch (RespuestaRapidaNoEncontradaException $e) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[webhooks/respuestas_rapidas.php] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Ocurrió un error al eliminar la respuesta.']);
    }
    exit;
}

if ($accion === 'adjunto_eliminar') {
    $adjuntoId = filter_var($body['adjunto_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$adjuntoId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Falta adjunto_id.']);
        exit;
    }

    try {
        $path = RespuestaRapidaRepository::eliminarAdjunto($adjuntoId);
        if ($path === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'El adjunto no existe.']);
            exit;
        }

        eliminarArchivoAdjuntoRespuesta($path);

        error_log("[webhooks/respuestas_rapidas.php] Adjunto id={$adjuntoId} eliminado vía token id={$tokenFila['id']}");
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        error_log('[webhooks/respuestas_rapidas.php] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Ocurrió un error al eliminar el adjunto.']);
    }
    exit;
}

if ($accion !== 'guardar') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "Acción '{$accion}' no reconocida."]);
    exit;
}

$id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
$titulo = trim((string) ($body['titulo'] ?? ''));
$texto = trim((string) ($body['texto'] ?? ''));
$canalIdRaw = trim((string) ($body['canal_id'] ?? ''));
$orden = filter_var($body['orden'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
$activo = !empty($body['activo']);

if ($titulo === '' || $texto === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Título y texto son obligatorios.']);
    exit;
}

$canalId = null;
if ($canalIdRaw !== '') {
    $canalId = filter_var($canalIdRaw, FILTER_VALIDATE_INT);
    $stmtCanal = $pdo->prepare('SELECT 1 FROM canales WHERE id = ? LIMIT 1');
    $stmtCanal->execute([$canalId]);
    if (!$canalId || !$stmtCanal->fetchColumn()) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'El canal seleccionado no es válido.']);
        exit;
    }
}

$datos = [
    'titulo'   => $titulo,
    'texto'    => $texto,
    'canal_id' => $canalId,
    'orden'    => $orden,
    'activo'   => $activo,
];

try {
    if ($id) {
        RespuestaRapidaRepository::editar($id, $datos);
        $respuestaId = $id;
    } else {
        $datos['creado_por'] = null;
        $respuestaId = RespuestaRapidaRepository::crear($datos);
    }

    if ($esMultipart && !empty($_FILES['adjuntos']['name'][0] ?? null)) {
        $cantidadArchivos = count($_FILES['adjuntos']['name']);
        for ($i = 0; $i < $cantidadArchivos; $i++) {
            if ($_FILES['adjuntos']['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }

            $archivo = [
                'name'     => $_FILES['adjuntos']['name'][$i],
                'tmp_name' => $_FILES['adjuntos']['tmp_name'][$i],
                'size'     => $_FILES['adjuntos']['size'][$i],
            ];

            $validacion = validarArchivoAdjuntoRespuesta($archivo);
            if ($validacion['error'] !== null) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => $validacion['error']]);
                exit;
            }

            $guardado = guardarAdjuntoRespuesta($archivo, $validacion['tipo']);
            RespuestaRapidaRepository::agregarAdjunto($respuestaId, $guardado);
        }
    }

    error_log("[webhooks/respuestas_rapidas.php] Respuesta id={$respuestaId} guardada vía token id={$tokenFila['id']}");
    echo json_encode(['ok' => true, 'id' => $respuestaId]);
} catch (RespuestaRapidaNoEncontradaException $e) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[webhooks/respuestas_rapidas.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ocurrió un error al guardar la respuesta.']);
}
