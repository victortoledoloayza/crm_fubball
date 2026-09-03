<?php
/**
 * core/pedidos/PrioridadCalculator.php
 *
 * Umbrales de prioridad automática (pedidos.prioridad), derivados del
 * tiempo restante hasta fecha_limite (interna). Mismos umbrales que ya
 * usa el resto del sistema para "urgente" (2h — ver
 * PedidoRepository::obtenerStatsOperativos() / obtenerChartSla()), no son
 * un número nuevo inventado acá.
 *
 * Las constantes son la única fuente de verdad: se usan tanto acá (PHP,
 * al crear un pedido) como en PedidoRepository::recalcularPrioridadesAutomaticas()
 * (SQL, en el refresco de 30s) — si cambian, cambian en un solo lugar y
 * ambos quedan sincronizados.
 */

class PrioridadCalculator
{
    public const UMBRAL_URGENTE_MINUTOS = 120;     // 2h
    public const UMBRAL_MUY_URGENTE_MINUTOS = 30;  // 30min (incluye vencido, que da diff negativo)

    public static function calcular(string $fechaLimite): string
    {
        $timestamp = strtotime($fechaLimite);
        if ($timestamp === false) {
            return 'normal';
        }

        $diffMinutos = ($timestamp - time()) / 60;

        if ($diffMinutos < self::UMBRAL_MUY_URGENTE_MINUTOS) {
            return 'muy_urgente';
        }
        if ($diffMinutos < self::UMBRAL_URGENTE_MINUTOS) {
            return 'urgente';
        }

        return 'normal';
    }
}
