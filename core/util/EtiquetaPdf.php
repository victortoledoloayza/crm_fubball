<?php
/**
 * core/util/EtiquetaPdf.php
 *
 * Validación y guardado de la etiqueta PDF de un pedido — compartido entre
 * api/pedido_subir_etiqueta.php (sesión, humano) y
 * api/webhooks/pedido_etiqueta.php (token API, extensión Chrome). Mismo
 * criterio de validación en ambos: tamaño, extensión y mime type real
 * (no solo el nombre del archivo).
 */

const MAX_BYTES_ETIQUETA_PDF = 15 * 1024 * 1024; // 15MB

// Valida un elemento de $_FILES como PDF real. Devuelve el mensaje de
// error a mostrar al usuario, o null si el archivo es válido.
function validarArchivoEtiquetaPdf(array $archivo): ?string
{
    if ($archivo['size'] <= 0 || $archivo['size'] > MAX_BYTES_ETIQUETA_PDF) {
        return 'El archivo debe ser un PDF de hasta 15MB.';
    }

    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    if ($extension !== 'pdf') {
        return 'El archivo debe tener extensión .pdf.';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeReal = finfo_file($finfo, $archivo['tmp_name']);
    finfo_close($finfo);

    if ($mimeReal !== 'application/pdf') {
        return 'El archivo no es un PDF válido.';
    }

    return null;
}

// Mueve el archivo ya validado a uploads/etiquetas/{codigo_orden}.pdf
// (reemplazando cualquier etiqueta previa del mismo pedido) y devuelve la
// URL pública. codigo_orden puede venir de una integración externa
// (Shopify, extensión Chrome) — se sanea antes de usarlo como nombre de
// archivo para descartar cualquier intento de path traversal.
function guardarEtiquetaPdf(array $archivo, string $codigoOrden): string
{
    $carpetaEtiquetas = __DIR__ . '/../../uploads/etiquetas';
    if (!is_dir($carpetaEtiquetas) && !mkdir($carpetaEtiquetas, 0755, true) && !is_dir($carpetaEtiquetas)) {
        throw new RuntimeException("No se pudo crear {$carpetaEtiquetas}");
    }

    $nombreArchivo = preg_replace('/[^A-Za-z0-9_-]/', '_', $codigoOrden) . '.pdf';
    $rutaDestino = $carpetaEtiquetas . '/' . $nombreArchivo;

    if (!move_uploaded_file($archivo['tmp_name'], $rutaDestino)) {
        throw new RuntimeException('move_uploaded_file falló para codigo_orden=' . $codigoOrden);
    }

    return baseUrl('uploads/etiquetas/' . $nombreArchivo);
}
