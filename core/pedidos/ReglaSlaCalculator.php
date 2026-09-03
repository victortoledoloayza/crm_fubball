<?php
/**
 * core/pedidos/ReglaSlaCalculator.php
 *
 * Aplica el "colchón" de seguridad interno de Almacén sobre la hora de
 * corte real del marketplace, según reglas_sla (por canal, configurable
 * desde configuracion_sla.php). Diseño genérico por canal — hoy solo
 * Falabella tiene reglas cargadas, pero cualquier canal puede tener 0, 1
 * o varias ventanas de corte.
 *
 * Se usa una sola vez, desde PedidoRepository::crear() — nunca se
 * reejecuta al editar un pedido (fecha_limite se edita directo).
 */

class ReglaSlaCalculator
{
    // Si la hora recibida no cae a menos de esta cantidad de minutos de
    // ninguna regla activa del canal, no se aplica ningún colchón — se
    // usa la hora marketplace tal cual, sin bloquear la creación del
    // pedido.
    private const TOLERANCIA_MINUTOS = 30;

    // $fechaHoraMarketplace: 'Y-m-d H:i:s', la hora real que mandó el
    // canal (o la que escribió el usuario en alta manual/TSI — ver nota
    // de UX en el diseño: para canales con reglas activas, ese único
    // campo del formulario se interpreta como hora marketplace).
    //
    // Devuelve ['fecha_limite' => DATETIME interna, 'fecha_limite_marketplace' => DATETIME recibida tal cual].
    public static function calcular(PDO $pdo, int $canalId, string $fechaHoraMarketplace): array
    {
        $resultado = [
            'fecha_limite'             => $fechaHoraMarketplace,
            'fecha_limite_marketplace' => $fechaHoraMarketplace,
        ];

        $timestamp = strtotime($fechaHoraMarketplace);
        if ($timestamp === false) {
            return $resultado;
        }

        $stmt = $pdo->prepare(
            'SELECT id, hora_corte_marketplace, hora_corte_interna
             FROM reglas_sla
             WHERE canal_id = ? AND activo = 1'
        );
        $stmt->execute([$canalId]);
        $reglas = $stmt->fetchAll();

        if (empty($reglas)) {
            return $resultado;
        }

        $minutosEntrante = (int) date('H', $timestamp) * 60 + (int) date('i', $timestamp);

        $mejorRegla = null;
        $mejorDiferencia = null;
        foreach ($reglas as $regla) {
            $minutosRegla = self::minutosDesdeTime($regla['hora_corte_marketplace']);
            $diferencia = abs($minutosEntrante - $minutosRegla);
            $diferencia = min($diferencia, 1440 - $diferencia); // tolera el cruce de medianoche

            if ($mejorDiferencia === null || $diferencia < $mejorDiferencia) {
                $mejorDiferencia = $diferencia;
                $mejorRegla = $regla;
            }
        }

        if ($mejorRegla === null || $mejorDiferencia > self::TOLERANCIA_MINUTOS) {
            error_log(
                "[ReglaSlaCalculator] canal_id={$canalId}: hora marketplace '{$fechaHoraMarketplace}' "
                . 'no cayó dentro de la tolerancia (' . self::TOLERANCIA_MINUTOS . ' min) de ninguna regla activa'
                . ($mejorRegla !== null ? " (la más cercana quedó a {$mejorDiferencia} min)" : '')
                . ' — se usa la hora marketplace sin ajuste.'
            );

            return $resultado;
        }

        $colchonMinutos = self::minutosDesdeTime($mejorRegla['hora_corte_marketplace'])
            - self::minutosDesdeTime($mejorRegla['hora_corte_interna']);

        // Regla mal configurada (interna >= marketplace, el colchón
        // debería restar tiempo, no sumarlo) — se ignora en vez de
        // "adelantar" la hora límite por error.
        if ($colchonMinutos <= 0) {
            error_log(
                "[ReglaSlaCalculator] regla_sla id={$mejorRegla['id']} inválida "
                . '(hora_corte_interna no es anterior a hora_corte_marketplace) — se ignora.'
            );

            return $resultado;
        }

        // No se "encaja" al horario fijo de la regla — se preserva el
        // minuto exacto recibido y se le resta el colchón, así una hora
        // marketplace que llega unos minutos corrida del valor configurado
        // no pierde precisión.
        $resultado['fecha_limite'] = date('Y-m-d H:i:s', $timestamp - $colchonMinutos * 60);

        return $resultado;
    }

    private static function minutosDesdeTime(string $horaTime): int
    {
        [$horas, $minutos] = explode(':', $horaTime);

        return ((int) $horas) * 60 + (int) $minutos;
    }
}
