<?php
/**
 * tsi.php
 *
 * Asistente TSI: mientras TSI ERP no tenga API abierta, este módulo evita
 * la triple digitación — genera el CSV con las columnas exactas y explica
 * el modo asistido (copiar pedido por pedido desde la Cola de
 * Facturación). Layout copiado de docs/prototipo-referencia.html
 * (pestaña "Asistente TSI").
 */

require_once __DIR__ . '/core/bootstrap.php';

Auth::requireLogin();

$tituloPagina = 'Asistente TSI';
$navActiva    = 'tsi';
require __DIR__ . '/core/ui/layout_header.php';
?>
    <style>
        :root {
            --border: #e5e7ee;
            --text: #1c2233;
            --text-muted: #6b7280;
            --radius: 12px;
        }
        .btn { border: none; border-radius: 9px; padding: 9px 14px; font-size: .8rem; font-weight: 700; cursor: pointer; font-family: inherit; }
        .btn-outline { background: #f2f3f7; color: var(--text); }
        .btn-outline:hover { background: #e7e9f0; }
        .btn-dark { background: var(--text); color: #fff; }
        .btn-dark:hover { background: #000000; }
        a.btn { display: inline-block; text-decoration: none; }

        .section-sub { color: var(--text-muted); font-size: .85rem; margin: 0 0 20px; max-width: 680px; }
        .tsi-grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: 20px; align-items: start; }
        @media (max-width: 900px) { .tsi-grid { grid-template-columns: 1fr; } }
        .panel-card { background: #fff; border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; }
        .panel-card h3 { margin: 0 0 6px; font-size: .95rem; }
        .panel-card p { color: var(--text-muted); font-size: .82rem; margin: 0 0 14px; }
        .col-chip { display: inline-block; background: #f2f3f7; border: 1px solid var(--border); border-radius: 7px; padding: 5px 10px; font-size: .74rem; font-weight: 700; margin: 3px 4px 0 0; font-family: monospace; }
    </style>

    <div class="section-sub">
        Mientras TSI no tenga API abierta, este módulo evita la triple digitación: genera el archivo con las
        columnas exactas y copia cada pedido listo para pegar.
    </div>

    <div class="tsi-grid">
        <div class="panel-card">
            <h3>Plantilla de importación TSI</h3>
            <p>Estas son las columnas que exportamos hoy. Sirven también como base para pedirle al proveedor de
                TSI que active la carga masiva.</p>
            <div>
                <span class="col-chip">Nombre</span><span class="col-chip">DNI</span><span class="col-chip">Dirección</span>
                <span class="col-chip">Producto</span><span class="col-chip">SKU</span><span class="col-chip">Cantidad</span>
                <span class="col-chip">Precio</span><span class="col-chip">Canal</span><span class="col-chip">N Orden</span>
            </div>
            <div style="margin-top:16px;">
                <a class="btn btn-dark" href="<?= htmlspecialchars(baseUrl('api/facturacion_exportar_csv.php'), ENT_QUOTES, 'UTF-8') ?>">⬇️ Generar archivo TSI de hoy</a>
            </div>
        </div>
        <div class="panel-card">
            <h3>Modo asistido (mientras se convive con TSI manual)</h3>
            <p>Para digitar boleta por boleta: entra a la Cola de Facturación, pulsa "📋 Copiar" en el pedido y
                pega directo en TSI — sin buscar en fotos ni en WhatsApp.</p>
            <a class="btn btn-outline" href="<?= htmlspecialchars(baseUrl('facturacion.php'), ENT_QUOTES, 'UTF-8') ?>">🧾 Ir a Cola de Facturación</a>
        </div>
    </div>
<?php
require __DIR__ . '/core/ui/layout_footer.php';
