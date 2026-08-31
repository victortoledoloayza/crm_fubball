<?php
/**
 * core/util/EtiquetaPdfRecorteFalabella.php
 *
 * Recorta la etiqueta PDF de Falabella al tamaño real del contenido
 * (~9.3x14.6cm) antes de guardarla, descartando el espacio en
 * blanco/gris que Falabella deja alrededor en la página A4 — Ripley ya
 * llega recortado de forma nativa, así que esto NUNCA se aplica a Ripley
 * (ver llamador en api/webhooks/pedido_etiqueta.php, que solo invoca esto
 * cuando canal_codigo === 'FALABELLA').
 *
 * El recorte en sí corre en Python (core/util/recortar_etiqueta_falabella.py,
 * con pypdf vendorizado en core/util/vendor/ para no depender de paquetes
 * instalados a nivel de usuario — el proceso de PHP corre como otro
 * usuario del sistema y no tendría acceso a ellos). Coordenadas fijas,
 * medidas empíricamente contra 3 etiquetas reales — ver comentario en el
 * script Python para el detalle.
 *
 * Best-effort: cualquier fallo (python3 no disponible, tamaño de página
 * inesperado, PDF corrupto) se loguea y se deja el PDF original sin
 * recortar — nunca debe bloquear la subida de la etiqueta.
 */

const PYTHON3_BIN_CANDIDATOS = [
    '/Library/Frameworks/Python.framework/Versions/3.13/bin/python3',
    '/usr/bin/python3',
    '/usr/local/bin/python3',
];

function recortarEtiquetaFalabellaSiAplica(string $rutaPdfTmp, string $codigoOrden): void
{
    $python = null;
    foreach (PYTHON3_BIN_CANDIDATOS as $candidato) {
        if (is_executable($candidato)) {
            $python = $candidato;
            break;
        }
    }

    if ($python === null) {
        error_log("[EtiquetaPdfRecorteFalabella] python3 no disponible en el servidor — se sube la etiqueta de {$codigoOrden} sin recortar.");
        return;
    }

    $script = __DIR__ . '/recortar_etiqueta_falabella.py';
    $cmd = escapeshellarg($python) . ' -S ' . escapeshellarg($script) . ' ' . escapeshellarg($rutaPdfTmp) . ' 2>&1';

    exec($cmd, $salida, $codigoSalida);

    if ($codigoSalida !== 0) {
        $detalle = implode(' | ', $salida);
        error_log("[EtiquetaPdfRecorteFalabella] No se pudo recortar la etiqueta de {$codigoOrden} (exit={$codigoSalida}): {$detalle} — se sube sin recortar.");
    }
}
