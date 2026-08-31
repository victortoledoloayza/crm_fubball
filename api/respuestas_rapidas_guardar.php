<?php
/**
 * api/respuestas_rapidas_guardar.php (POST, multipart/form-data, sesión)
 *
 * Crea o edita una respuesta rápida (id presente y > 0 = edición) y de
 * paso agrega los adjuntos nuevos que vengan en el campo de archivos
 * adjuntos[] (0, 1 o varios — ver respuestas_rapidas_adjuntos). Para
 * quitar un adjunto existente se usa el endpoint aparte
 * respuestas_rapidas_adjunto_eliminar.php, no este.
 *
 * Campos: id (opcional), titulo, texto, canal_id (opcional, vacío = todos
 * los canales), orden, activo ('1'/ausente), csrf_token, adjuntos[]
 * (opcional, archivos).
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/respuestas/RespuestaRapidaRepository.php';
require_once __DIR__ . '/../core/util/RespuestaAdjunto.php';

$usuarioSesion = Auth::requireLogin();

header('Content-Type: application/json; charset=utf-8');

csrfRequerir();

$pdo = Database::getConnection();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$titulo = trim($_POST['titulo'] ?? '');
$texto = trim($_POST['texto'] ?? '');
$canalIdRaw = trim((string) ($_POST['canal_id'] ?? ''));
$orden = filter_input(INPUT_POST, 'orden', FILTER_VALIDATE_INT) ?: 0;
$activo = !empty($_POST['activo']);

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
        $datos['creado_por'] = (int) $usuarioSesion['id'];
        $respuestaId = RespuestaRapidaRepository::crear($datos);
    }

    // Adjuntos nuevos: input type="file" multiple="multiple" name="adjuntos[]"
    // llega como $_FILES['adjuntos'] con arrays paralelos (name, tmp_name, ...).
    if (!empty($_FILES['adjuntos']['name'][0] ?? null)) {
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

    error_log("[respuestas_rapidas_guardar.php] Respuesta id={$respuestaId} guardada por usuario id={$usuarioSesion['id']}");
    echo json_encode(['ok' => true, 'id' => $respuestaId]);
} catch (RespuestaRapidaNoEncontradaException $e) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[respuestas_rapidas_guardar.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ocurrió un error al guardar la respuesta.']);
}
