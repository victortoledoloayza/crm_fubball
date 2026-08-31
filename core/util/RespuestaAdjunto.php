<?php
/**
 * core/util/RespuestaAdjunto.php
 *
 * Validación y guardado de los adjuntos de Respuestas Rápidas — compartido
 * entre api/respuestas_rapidas_guardar.php (sesión, panel admin) y
 * api/webhooks/respuestas_rapidas.php (token API, extensión Chrome). El
 * tipo (imagen/video/pdf/audio/documento, ver ENUM de
 * respuestas_rapidas_adjuntos) se detecta automáticamente por la
 * extensión real del archivo — ni el panel ni la extensión necesitan
 * mandarlo a mano.
 */

const MAX_BYTES_ADJUNTO_RESPUESTA = 25 * 1024 * 1024; // 25MB

// extensión (en minúsculas, sin punto) => tipo ENUM correspondiente.
const EXTENSIONES_ADJUNTO_RESPUESTA = [
    'jpg'  => 'imagen', 'jpeg' => 'imagen', 'png' => 'imagen', 'gif' => 'imagen', 'webp' => 'imagen',
    'mp4'  => 'video', 'mov' => 'video', 'webm' => 'video', 'avi' => 'video',
    'pdf'  => 'pdf',
    'mp3'  => 'audio', 'wav' => 'audio', 'ogg' => 'audio', 'm4a' => 'audio',
    'doc'  => 'documento', 'docx' => 'documento', 'xls' => 'documento', 'xlsx' => 'documento',
    'ppt'  => 'documento', 'pptx' => 'documento', 'txt' => 'documento', 'csv' => 'documento',
];

// mime real (finfo) que se acepta para cada tipo — defensa en profundidad
// además de la extensión, igual que EtiquetaPdf.php hace con 'application/pdf'.
const MIMES_ADJUNTO_RESPUESTA_POR_TIPO = [
    'imagen'    => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    'video'     => ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-msvideo'],
    'pdf'       => ['application/pdf'],
    'audio'     => ['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/mp4', 'audio/x-m4a'],
    'documento' => [
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain',
        'text/csv',
    ],
];

// Valida un elemento de $_FILES: tamaño, extensión reconocida y mime type
// real coherente con esa extensión. Devuelve ['tipo' => ..., 'error' => null]
// si es válido, o ['tipo' => null, 'error' => 'mensaje'] si no.
function validarArchivoAdjuntoRespuesta(array $archivo): array
{
    if ($archivo['size'] <= 0 || $archivo['size'] > MAX_BYTES_ADJUNTO_RESPUESTA) {
        return ['tipo' => null, 'error' => 'Cada adjunto debe pesar hasta 25MB.'];
    }

    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    $tipo = EXTENSIONES_ADJUNTO_RESPUESTA[$extension] ?? null;

    if ($tipo === null) {
        return ['tipo' => null, 'error' => "Extensión '.{$extension}' no admitida para adjuntos."];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeReal = finfo_file($finfo, $archivo['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeReal, MIMES_ADJUNTO_RESPUESTA_POR_TIPO[$tipo], true)) {
        return ['tipo' => null, 'error' => "El archivo '{$archivo['name']}' no es un {$tipo} válido."];
    }

    return ['tipo' => $tipo, 'error' => null];
}

// Mueve el archivo ya validado a uploads/respuestas_rapidas/ con un nombre
// aleatorio (a diferencia de la etiqueta PDF, acá no hay un codigo_orden
// natural para nombrar el archivo, y puede haber varios adjuntos por
// respuesta) y devuelve los datos a guardar en
// respuestas_rapidas_adjuntos. Aplica chmod tras crear la carpeta porque
// el checkout de git ya trae uploads/respuestas_rapidas/ creada por el
// usuario del sistema de archivos, que no siempre coincide con el usuario
// bajo el que corre PHP (mismo problema ya resuelto para uploads/etiquetas).
function guardarAdjuntoRespuesta(array $archivo, string $tipo): array
{
    $carpeta = __DIR__ . '/../../uploads/respuestas_rapidas';
    if (!is_dir($carpeta) && !mkdir($carpeta, 0777, true) && !is_dir($carpeta)) {
        throw new RuntimeException("No se pudo crear {$carpeta}");
    }
    @chmod($carpeta, 0777);

    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    $nombreArchivo = bin2hex(random_bytes(16)) . '.' . $extension;
    $rutaDestino = $carpeta . '/' . $nombreArchivo;

    if (!move_uploaded_file($archivo['tmp_name'], $rutaDestino)) {
        throw new RuntimeException('move_uploaded_file falló para adjunto ' . $archivo['name']);
    }
    @chmod($rutaDestino, 0644);

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeReal = finfo_file($finfo, $rutaDestino);
    finfo_close($finfo);

    return [
        'tipo'           => $tipo,
        'path'           => 'uploads/respuestas_rapidas/' . $nombreArchivo,
        'nombre_archivo' => $archivo['name'],
        'mime_type'      => $mimeReal,
    ];
}

// Borrado best-effort del archivo físico — se llama después de borrar la
// fila en BD (o su respuesta dueña), nunca antes, para no dejar la BD
// apuntando a un archivo ya borrado si algo falla a mitad de camino.
function eliminarArchivoAdjuntoRespuesta(string $path): void
{
    $rutaAbsoluta = __DIR__ . '/../../' . $path;
    if (is_file($rutaAbsoluta) && !@unlink($rutaAbsoluta)) {
        error_log("[RespuestaAdjunto] No se pudo borrar {$rutaAbsoluta}");
    }
}
