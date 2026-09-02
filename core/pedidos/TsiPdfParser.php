<?php
/**
 * core/pedidos/TsiPdfParser.php
 *
 * Extrae los campos de una Orden de Pedido en PDF del ERP TSI para
 * pre-llenar el formulario de alta manual (pedidos_nuevo.php) — nunca crea
 * el pedido directamente, solo devuelve datos para que el usuario revise
 * antes de confirmar (ver api/tsi_extraer_pdf.php).
 *
 * El texto que devuelve smalot/pdfparser sale en el orden en que el motor
 * de reportes de TSI dibujó el contenido (todas las etiquetas de una
 * columna, luego todos los ":", luego todos los valores), NO en orden
 * visual de lectura — un regex tipo "Cliente\s*:\s*(.+)" no encuentra nada
 * sobre ese texto crudo. reconstruirLineas() usa las coordenadas X/Y de
 * cada fragmento (Page::getDataTm()) para reagruparlos en filas visuales
 * reales antes de aplicar los regex, igual que "pdftotext -layout".
 */

require_once __DIR__ . '/../vendor/autoload_pdfparser.php';

class TsiPdfParser
{
    // Ancho de caracter promedio (pt) del documento TSI — solo se usa para
    // decidir si el espacio entre dos fragmentos es "la misma frase" o "una
    // columna distinta"; no necesita ser exacto, solo separar palabras
    // sueltas de saltos de columna reales (ver UMBRAL_SALTO_COLUMNA).
    private const ANCHO_CHAR_PROMEDIO = 4.6;
    private const UMBRAL_SALTO_COLUMNA = 12.0;
    private const TOLERANCIA_FILA_Y = 2.0;

    // Campos que se espera poder extraer siempre de una Orden TSI válida —
    // los que no matcheen quedan en null y se listan en 'campos_faltantes'
    // para que el formulario los marque como "complétalos a mano".
    private const CAMPOS_A_REVISAR = [
        'codigo_orden', 'cliente_nombre', 'cliente_dni',
        'cliente_telefono', 'cliente_direccion', 'fecha_limite',
    ];

    public static function parseArchivo(string $rutaArchivo): array
    {
        $resultado = [
            'reconocido' => false,
            'error' => null,
            'campos' => [
                'codigo_orden'      => null,
                'cliente_nombre'    => null,
                'cliente_dni'       => null,
                'cliente_telefono'  => null,
                'cliente_direccion' => null,
                'fecha_limite'      => null,
                'moneda'            => 'PEN',
                'costo_envio'       => 0.0,
                'items'             => [],
            ],
            'campos_faltantes' => [],
        ];

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($rutaArchivo);
        } catch (\Throwable $e) {
            error_log('[TsiPdfParser] No se pudo parsear el PDF: ' . $e->getMessage());
            $resultado['error'] = 'No se pudo leer el archivo como PDF. Completa el formulario manualmente.';

            return $resultado;
        }

        $texto = '';
        foreach ($pdf->getPages() as $pagina) {
            $texto .= self::reconstruirLineas($pagina) . "\n";
        }

        // Ancla de reconocimiento: si el PDF no trae estos dos textos, no es
        // una Orden de Pedido TSI (o vino de otro formato) — se corta acá en
        // vez de devolver campos parseados a medias/erróneos.
        if (strpos($texto, 'ORDEN DE PEDIDO') === false || strpos($texto, 'Cliente') === false) {
            $resultado['error'] = 'No se pudo reconocer el formato — no parece una Orden de Pedido de TSI. Completa el formulario manualmente.';

            return $resultado;
        }

        $resultado['reconocido'] = true;
        $campos = &$resultado['campos'];

        // codigo_orden: en este PDF la etiqueta "ORDEN DE PEDIDO" y el
        // número van en líneas separadas (no "label : valor").
        if (preg_match('/ORDEN DE PEDIDO\s*\n\s*(\d+)\s*-\s*(\d+)/u', $texto, $m)) {
            $campos['codigo_orden'] = 'TSI-' . $m[1] . '-' . $m[2];
        }

        if (preg_match('/Cliente\s*:\s*(.+?)(?=\s{2,}|\n|$)/u', $texto, $m)) {
            $campos['cliente_nombre'] = trim($m[1]);
        }

        // "RUC." (con punto) es el del cliente. "RUC:" (sin punto, en el
        // encabezado) es el de Fubball, la empresa emisora — nunca
        // confundirlos, aunque ambos aparezcan en el mismo documento.
        if (preg_match('/RUC\.\s*:\s*(\S+)/u', $texto, $m)) {
            $campos['cliente_dni'] = $m[1];
        }

        if (preg_match('/Tel[ée]fono\s*:\s*(.+?)(?=\s{2,}|\n|$)/u', $texto, $m)) {
            $campos['cliente_telefono'] = trim($m[1]);
        }

        if (preg_match('/Direcci[oó]n\s*:\s*(.+?)(?=\s{2,}|\n|$)/u', $texto, $m)) {
            $campos['cliente_direccion'] = trim($m[1]);
        }

        $fecha = null;
        $hora = '00:00:00';
        if (preg_match('/F\.Entrega\s*:\s*(\d{2})\/(\d{2})\/(\d{4})/u', $texto, $m)) {
            $fecha = "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('/H\.Entrega\s*:?\s*(\d{2}:\d{2}:\d{2})/u', $texto, $m)) {
            $hora = $m[1];
        }
        if ($fecha !== null) {
            $campos['fecha_limite'] = "{$fecha} {$hora}";
        }

        $campos['items'] = self::parsearItems($texto);

        foreach (self::CAMPOS_A_REVISAR as $campo) {
            if ($campos[$campo] === null || $campos[$campo] === '') {
                $resultado['campos_faltantes'][] = $campo;
            }
        }
        if (empty($campos['items'])) {
            $resultado['campos_faltantes'][] = 'items';
        }

        return $resultado;
    }

    // Cada fila de la tabla de ítems tiene una ancla muy confiable: la
    // columna P.UNIT. es el único número con 6 decimales (88.000000) —
    // los descuentos (D1%..D4%) y el S/ Total de la fila tienen 2
    // (0.00 / 88.00), así que no hay ambigüedad entre columnas aunque
    // cambie el espaciado o el ancho de la descripción.
    private static function parsearItems(string $texto): array
    {
        $items = [];

        if (preg_match_all(
            '/^\d+\s+([\d.]+)\s+\S+\s+(\S+)\s+(.+?)\s+\d+\s+(\d+\.\d{6})/mu',
            $texto,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $items[] = [
                    'producto_nombre' => trim($m[3]),
                    'sku'             => $m[2],
                    'cantidad'        => (int) round((float) $m[1]),
                    'precio_unitario' => (float) $m[4],
                ];
            }
        }

        return $items;
    }

    // Reagrupa los fragmentos de texto de una página por fila visual (misma
    // Y, con tolerancia) y los ordena por X dentro de cada fila. Cuando el
    // salto horizontal entre dos fragmentos es grande (UMBRAL_SALTO_COLUMNA)
    // se marca con espacio triple en vez de uno solo, para que los regex de
    // campo (que se detienen en "\s{2,}") no arrastren texto de una columna
    // vecina que cayó por coincidencia en la misma fila (ej. una marca de
    // agua superpuesta).
    private static function reconstruirLineas(\Smalot\PdfParser\Page $pagina): string
    {
        $datos = $pagina->getDataTm();

        $fragmentos = [];
        foreach ($datos as $entrada) {
            $tm = $entrada[0];
            $texto = $entrada[1];
            if (trim($texto) === '') {
                continue;
            }
            $fragmentos[] = ['x' => (float) $tm[4], 'y' => (float) $tm[5], 'texto' => $texto];
        }

        // Orden estable por Y descendente (arriba->abajo en coordenadas PDF)
        // para que el agrupamiento por fila procese en orden visual.
        usort($fragmentos, fn (array $a, array $b): int => $b['y'] <=> $a['y']);

        $filas = [];
        foreach ($fragmentos as $frag) {
            $colocado = false;
            foreach ($filas as &$fila) {
                if (abs($fila['y'] - $frag['y']) <= self::TOLERANCIA_FILA_Y) {
                    $fila['items'][] = $frag;
                    $colocado = true;
                    break;
                }
            }
            unset($fila);
            if (!$colocado) {
                $filas[] = ['y' => $frag['y'], 'items' => [$frag]];
            }
        }

        $lineas = [];
        foreach ($filas as $fila) {
            usort($fila['items'], fn (array $a, array $b): int => $a['x'] <=> $b['x']);

            $partes = [];
            $finXAnterior = null;
            foreach ($fila['items'] as $item) {
                $t = trim($item['texto']);
                if ($finXAnterior !== null) {
                    $espacio = $item['x'] - $finXAnterior;
                    $partes[] = $espacio > self::UMBRAL_SALTO_COLUMNA ? '   ' : ' ';
                }
                $partes[] = $t;
                $finXAnterior = $item['x'] + mb_strlen($t) * self::ANCHO_CHAR_PROMEDIO;
            }
            $lineas[] = implode('', $partes);
        }

        return implode("\n", $lineas);
    }
}
