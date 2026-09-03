<?php
/**
 * core/pedidos/EtiquetaGenerator.php
 *
 * Genera la etiqueta de despacho propia (4x6", 288x432pt) para pedidos que
 * no llegan con etiqueta ya hecha del marketplace (TSI, Shopify, WhatsApp,
 * y altas manuales) — ver api/pedido_generar_etiqueta.php.
 *
 * Guarda el PDF en uploads/etiquetas/{codigo_orden}.pdf, exactamente la
 * misma carpeta y convención de nombre que pedido_subir_etiqueta.php, para
 * que el resto del sistema (el link "#codigo_orden", el botón "🖨️
 * Etiqueta") funcione igual sin importar si la etiqueta vino subida a mano
 * o generada acá.
 */

require_once __DIR__ . '/../vendor/setasign/fpdf/fpdf.php';
require_once __DIR__ . '/../util/Code128.php';

class EtiquetaGenerator
{
    private const ANCHO_PT = 288.0;  // 4in
    private const ALTO_PT = 432.0;   // 6in
    private const MARGEN = 18.0;
    private const GRIS_LABEL = [139, 143, 156];
    private const NEGRO = [28, 34, 51];

    // $pedido: fila de PedidoRepository::obtener() (con 'items' adjuntado).
    // Devuelve el nombre de archivo generado (no la ruta completa).
    public static function generarParaPedido(array $pedido): string
    {
        $pedido = self::normalizarTextoPedido($pedido);

        $pdf = new FPDF('P', 'pt', [self::ANCHO_PT, self::ALTO_PT]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(self::MARGEN, self::MARGEN, self::MARGEN);
        $pdf->AddPage();
        $pdf->SetCreator('Fubball KDS');
        $pdf->SetTitle('Etiqueta ' . $pedido['codigo_orden']);

        $anchoUtil = self::ANCHO_PT - 2 * self::MARGEN;

        self::dibujarEncabezado($pdf, $pedido, $anchoUtil);
        self::dibujarDestinatario($pdf, $pedido, $anchoUtil);
        self::dibujarOrden($pdf, $pedido, $anchoUtil);
        self::dibujarMeta($pdf, $pedido, $anchoUtil);
        self::dibujarContenido($pdf, $pedido, $anchoUtil);
        self::dibujarBarcode($pdf, $pedido['codigo_orden']);

        $carpetaEtiquetas = __DIR__ . '/../../uploads/etiquetas';
        if (!is_dir($carpetaEtiquetas) && !mkdir($carpetaEtiquetas, 0755, true) && !is_dir($carpetaEtiquetas)) {
            throw new RuntimeException("No se pudo crear la carpeta {$carpetaEtiquetas}.");
        }

        // codigo_orden puede venir de una integración externa — se sanea
        // antes de usarlo como nombre de archivo, mismo criterio que
        // pedido_subir_etiqueta.php.
        $nombreArchivo = preg_replace('/[^A-Za-z0-9_-]/', '_', $pedido['codigo_orden']) . '.pdf';
        $rutaDestino = $carpetaEtiquetas . '/' . $nombreArchivo;

        $pdf->Output('F', $rutaDestino);

        return $nombreArchivo;
    }

    private static function dibujarEncabezado(FPDF $pdf, array $pedido, float $anchoUtil): void
    {
        // Ancho del badge según el texto real del canal (TSI es angosto,
        // WHATSAPP no) — le deja más espacio al bloque de remitente cuando
        // el código es corto, en vez de reservar siempre el peor caso.
        $pdf->SetFont('Helvetica', 'B', 9);
        $codigoCanal = strtoupper($pedido['canal_codigo']);
        $anchoBadge = $pdf->GetStringWidth($codigoCanal) + 16;
        $anchoRemitente = $anchoUtil - $anchoBadge - 10;

        $pdf->SetXY(self::MARGEN, self::MARGEN);
        $pdf->SetTextColor(...self::NEGRO);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell($anchoRemitente, 11, 'FUBBALL PERU S.A.', 0, 2);

        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetX(self::MARGEN);
        $pdf->MultiCell(
            $anchoRemitente,
            9,
            "RUC: 20548890897\n"
            . "Jiron San Patricio Mz. R1 Lt. 7, Urb. Villa Marina, Chorrillos - Lima\n"
            . 'Tel: (511) 345 9097 / 989 806 582 / 949 356 023 / 951 290 300',
            0,
            'L'
        );
        // Capturado ANTES de dibujar el badge — GetXY() del badge reposiciona
        // el cursor arriba de nuevo, así que leerlo después de eso pierde
        // cuánto creció este bloque (bug real: destinatario quedaba pintado
        // encima del remitente cuando el texto envolvía a 4-5 líneas).
        $yTrasRemitente = $pdf->GetY();

        // Badge de canal (arriba a la derecha), con el mismo color que
        // channelMeta ya usa en pedidos.php (canales.color_hex).
        [$r, $g, $b] = self::hexARgb($pedido['canal_color'] ?? '#666666');
        $pdf->SetFillColor($r, $g, $b);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetXY(self::ANCHO_PT - self::MARGEN - $anchoBadge, self::MARGEN);
        $pdf->Cell($anchoBadge, 16, $codigoCanal, 0, 0, 'C', true);
        $pdf->SetTextColor(...self::NEGRO);
        $yTrasBadge = self::MARGEN + 16;

        $y = max($yTrasRemitente, $yTrasBadge) + 6;
        $pdf->SetDrawColor(...self::NEGRO);
        $pdf->SetLineWidth(1.2);
        $pdf->Line(self::MARGEN, $y, self::ANCHO_PT - self::MARGEN, $y);
        $pdf->SetY($y + 10);
    }

    private static function dibujarDestinatario(FPDF $pdf, array $pedido, float $anchoUtil): void
    {
        self::etiquetaCampo($pdf, 'DESTINATARIO', $anchoUtil);

        $nombre = self::ajustarTextoUnaLinea($pdf, 'Helvetica', $pedido['cliente_nombre'], $anchoUtil, 16, 10);
        $pdf->SetX(self::MARGEN);
        $pdf->Cell($anchoUtil, $nombre['tamano'] + 3, $nombre['texto'], 0, 2);

        $pdf->SetFont('Helvetica', '', 10);
        $direccion = $pedido['cliente_direccion'] !== null && $pedido['cliente_direccion'] !== ''
            ? $pedido['cliente_direccion']
            : '-';
        // Deja ~2 líneas de margen a MultiCell antes de forzar el corte —
        // evita que una dirección desmedidamente larga empuje el resto del
        // layout fuera de la etiqueta.
        $direccion = self::truncarAlAncho($pdf, $direccion, $anchoUtil * 2.3);
        $pdf->SetX(self::MARGEN);
        $pdf->MultiCell($anchoUtil, 12, $direccion, 0, 'L');

        $pdf->SetX(self::MARGEN);
        $telefono = 'Tel: ' . ($pedido['cliente_telefono'] !== null && $pedido['cliente_telefono'] !== '' ? $pedido['cliente_telefono'] : '-');
        $pdf->Cell($anchoUtil, 12, self::truncarAlAncho($pdf, $telefono, $anchoUtil), 0, 2);

        if (!empty($pedido['cliente_dni'])) {
            self::etiquetaCampo($pdf, 'DNI / RUC', $anchoUtil);
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->SetX(self::MARGEN);
            $pdf->Cell($anchoUtil, 12, self::truncarAlAncho($pdf, $pedido['cliente_dni'], $anchoUtil), 0, 2);
        }

        $pdf->SetY($pdf->GetY() + 8);
    }

    private static function dibujarOrden(FPDF $pdf, array $pedido, float $anchoUtil): void
    {
        self::etiquetaCampo($pdf, 'N. DE ORDEN', $anchoUtil);

        $codigo = self::ajustarTextoUnaLinea($pdf, 'Courier', $pedido['codigo_orden'], $anchoUtil, 20, 12);
        $pdf->SetX(self::MARGEN);
        $pdf->SetFont('Courier', 'B', $codigo['tamano']);
        $pdf->Cell($anchoUtil, $codigo['tamano'] + 4, $codigo['texto'], 0, 2);

        $pdf->SetY($pdf->GetY() + 8);
    }

    private static function dibujarMeta(FPDF $pdf, array $pedido, float $anchoUtil): void
    {
        $mitad = $anchoUtil / 2;

        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->SetTextColor(...self::GRIS_LABEL);
        $pdf->SetX(self::MARGEN);
        $pdf->Cell($mitad, 9, 'FECHA LIMITE', 0, 0);
        $pdf->Cell($mitad, 9, 'METODO DE DESPACHO', 0, 2);
        $pdf->SetTextColor(...self::NEGRO);

        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetX(self::MARGEN);
        $fecha = date('d/m/Y H:i', strtotime($pedido['fecha_limite']));
        $metodo = $pedido['metodo_nombre'] ?? 'Sin asignar';
        $metodo = self::truncarAlAncho($pdf, $metodo, $mitad - 4);
        $pdf->Cell($mitad, 13, $fecha, 0, 0);
        $pdf->Cell($mitad, 13, $metodo, 0, 2);

        $pdf->SetY($pdf->GetY() + 8);
    }

    private static function dibujarContenido(FPDF $pdf, array $pedido, float $anchoUtil): void
    {
        self::etiquetaCampo($pdf, 'CONTENIDO', $anchoUtil);

        $pdf->SetFont('Helvetica', '', 9.5);
        $resumen = self::resumenProductos($pedido['items'] ?? []);
        $pdf->SetX(self::MARGEN);
        $pdf->Cell($anchoUtil, 12, self::truncarAlAncho($pdf, $resumen, $anchoUtil), 0, 2);
    }

    // Code128-B vectorial (ver core/util/Code128.php), en una franja de
    // altura fija pegada al borde inferior — así el barcode nunca se monta
    // con el contenido de arriba, sin importar cuánto haya crecido (el
    // contenido del medio termina antes por los truncados/ajustes previos).
    private static function dibujarBarcode(FPDF $pdf, string $codigoOrden): void
    {
        $anchoUtil = self::ANCHO_PT - 2 * self::MARGEN;
        $altoBarras = 68.0;
        $y = self::ALTO_PT - self::MARGEN - $altoBarras - 16;

        $barras = Code128::codificar($codigoOrden);
        $totalModulos = array_sum(array_column($barras, 'ancho'));
        $anchoModulo = $anchoUtil / $totalModulos;

        $pdf->SetFillColor(0, 0, 0);
        $x = self::MARGEN;
        foreach ($barras as $b) {
            $w = $b['ancho'] * $anchoModulo;
            if ($b['barra']) {
                $pdf->Rect($x, $y, $w, $altoBarras, 'F');
            }
            $x += $w;
        }

        $pdf->SetTextColor(...self::NEGRO);
        $pdf->SetFont('Courier', '', 9);
        $pdf->SetXY(self::MARGEN, $y + $altoBarras + 4);
        $pdf->Cell($anchoUtil, 11, $codigoOrden, 0, 0, 'C');
    }

    private static function etiquetaCampo(FPDF $pdf, string $texto, float $anchoUtil): void
    {
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->SetTextColor(...self::GRIS_LABEL);
        $pdf->SetX(self::MARGEN);
        $pdf->Cell($anchoUtil, 9, $texto, 0, 2);
        $pdf->SetTextColor(...self::NEGRO);
    }

    // "4 productos - GUANTES BOXEO...", o el nombre completo si es un solo
    // ítem. La cantidad va PRIMERO a propósito: si el nombre del producto
    // es largo y truncarAlAncho() se come el resto, lo que sobrevive es el
    // dato más importante (cuántos productos hay), no al revés.
    private static function resumenProductos(array $items): string
    {
        if (empty($items)) {
            return 'Sin productos';
        }
        $primero = $items[0]['producto_nombre'];
        $cantidadItems = count($items);
        if ($cantidadItems === 1) {
            return $primero;
        }

        return $cantidadItems . ' productos - ' . $primero;
    }

    // Reduce el tamaño de fuente hasta que el texto entre en una sola
    // línea; si ni al tamaño mínimo entra, trunca con "…". Nunca devuelve
    // texto que se salga del ancho disponible.
    private static function ajustarTextoUnaLinea(
        FPDF $pdf,
        string $familia,
        string $texto,
        float $anchoMax,
        int $tamanoInicial,
        int $tamanoMinimo
    ): array {
        for ($tamano = $tamanoInicial; $tamano >= $tamanoMinimo; $tamano--) {
            $pdf->SetFont($familia, 'B', $tamano);
            if ($pdf->GetStringWidth($texto) <= $anchoMax) {
                return ['texto' => $texto, 'tamano' => $tamano];
            }
        }
        $pdf->SetFont($familia, 'B', $tamanoMinimo);

        return ['texto' => self::truncarAlAncho($pdf, $texto, $anchoMax), 'tamano' => $tamanoMinimo];
    }

    // Asume que ya se llamó SetFont() con la fuente/tamaño que se va a usar
    // para dibujar $texto — GetStringWidth() depende de la fuente activa.
    private static function truncarAlAncho(FPDF $pdf, string $texto, float $anchoMax): string
    {
        if ($pdf->GetStringWidth($texto) <= $anchoMax) {
            return $texto;
        }
        // Los textos que llegan acá ya están convertidos a ISO-8859-1 (ver
        // normalizarTextoPedido) — un byte por caracter, igual que
        // GetStringWidth() de FPDF, así que se corta byte a byte, no con
        // la semántica UTF-8 por defecto de mb_substr.
        $truncado = $texto;
        while ($truncado !== '' && $pdf->GetStringWidth($truncado . '...') > $anchoMax) {
            $truncado = mb_substr($truncado, 0, -1, 'ISO-8859-1');
        }

        return $truncado === '' ? '...' : $truncado . '...';
    }

    // Las fuentes core de FPDF (Helvetica/Courier) esperan texto en
    // ISO-8859-1/CP1252, no UTF-8 — sin esto, cualquier tilde o "ñ" que
    // venga de la BD (utf8mb4) sale como caracteres corruptos en el PDF.
    // codigo_orden y canal_codigo no se tocan: siempre son ASCII puro
    // (nuestros propios prefijos o códigos de canal en mayúsculas) y
    // codigo_orden además alimenta el barcode, que debe quedarse en su
    // codificación original.
    private static function normalizarTextoPedido(array $pedido): array
    {
        foreach (['cliente_nombre', 'cliente_direccion', 'cliente_telefono', 'cliente_dni', 'metodo_nombre'] as $campo) {
            if (isset($pedido[$campo]) && is_string($pedido[$campo])) {
                $pedido[$campo] = self::utf8ALatin1($pedido[$campo]);
            }
        }

        if (!empty($pedido['items']) && is_array($pedido['items'])) {
            foreach ($pedido['items'] as $i => $item) {
                if (isset($item['producto_nombre'])) {
                    $pedido['items'][$i]['producto_nombre'] = self::utf8ALatin1($item['producto_nombre']);
                }
            }
        }

        return $pedido;
    }

    private static function utf8ALatin1(string $texto): string
    {
        $convertido = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $texto);

        return $convertido !== false ? $convertido : $texto;
    }

    private static function hexARgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return [102, 102, 102];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
