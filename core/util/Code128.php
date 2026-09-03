<?php
/**
 * core/util/Code128.php
 *
 * Codificador Code128 Set B (ISO/IEC 15417) — sin librería vendored aparte.
 * Devuelve la secuencia de barras/espacios (en módulos) para dibujarlos como
 * rectángulos vectoriales en el PDF (ver core/pedidos/EtiquetaGenerator.php),
 * en vez de generar un PNG rasterizado que dependería de GD.
 *
 * La tabla de patrones es el estándar público ISO/IEC 15417 — se transcribió
 * y verificó contra el código fuente de picqer/php-barcode-generator
 * (src/Types/TypeCode128.php) para descartar errores de transcripción, no
 * se copió su implementación (que además soporta A/B/C automático y otras
 * cosas que acá no hacen falta: codigo_orden siempre es ASCII imprimible).
 */

class Code128
{
    // Índices 0-95: caracteres ASCII 32-127 (Set B), en el mismo orden que
    // el código ASCII (índice = ord(char) - 32). 96-102: funciones FNC no
    // usadas acá. 103/104/105: START A/B/C. 106: STOP (más una barra final
    // de cierre de 2 módulos que se agrega aparte, ver codificar()).
    private const TABLA = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '233111',
    ];

    private const START_B = 104;
    private const STOP = 106;
    private const BARRA_FINAL_MODULOS = 2;

    /**
     * @return array<int, array{ancho: int, barra: bool}> secuencia de
     *   elementos en módulos — 'barra' true = negro, false = espacio.
     */
    public static function codificar(string $texto): array
    {
        if ($texto === '') {
            throw new InvalidArgumentException('Code128: el texto no puede estar vacío.');
        }

        $datos = [];
        $len = strlen($texto);
        for ($i = 0; $i < $len; $i++) {
            $valor = ord($texto[$i]) - 32;
            if ($valor < 0 || $valor > 95) {
                throw new InvalidArgumentException(
                    "Code128: caracter '{$texto[$i]}' fuera de rango (Set B solo soporta ASCII 32-127)."
                );
            }
            $datos[] = $valor;
        }

        // Checksum: valor de arranque + suma(valor_i * posición_i, posición
        // arrancando en 1), mod 103 — algoritmo estándar Code128.
        $suma = self::START_B;
        foreach ($datos as $i => $valor) {
            $suma += $valor * ($i + 1);
        }
        $checksum = $suma % 103;

        $simbolos = array_merge([self::START_B], $datos, [$checksum, self::STOP]);

        $barras = [];
        foreach ($simbolos as $simbolo) {
            $patron = self::TABLA[$simbolo];
            for ($j = 0; $j < 6; $j++) {
                $barras[] = ['ancho' => (int) $patron[$j], 'barra' => $j % 2 === 0];
            }
        }
        $barras[] = ['ancho' => self::BARRA_FINAL_MODULOS, 'barra' => true];

        return $barras;
    }
}
