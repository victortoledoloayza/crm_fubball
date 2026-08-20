<?php
/**
 * index.php
 *
 * Panel: stats operativos, KPIs de trazabilidad y 4 gráficos, todo
 * calculado con SQL real (ver api/panel_datos.php y
 * core/pedidos/PedidoRepository.php). Layout/CSS/lógica de render de
 * gráficos copiados de docs/prototipo-referencia.html (pestaña "Panel"),
 * adaptados a consumir datos del servidor en vez del array `orders`.
 */

require_once __DIR__ . '/core/bootstrap.php';

$usuario = Auth::requireLogin();

$tituloPagina = 'Panel';
$navActiva    = 'panel';
require __DIR__ . '/core/ui/layout_header.php';
?>
    <style>
        :root {
            --border: #e5e7ee;
            --card-bg: #ffffff;
            --text: #1c2233;
            --text-muted: #6b7280;
            --green: #2fae66;
            --yellow: #e8a52a;
            --red: #d6483d;
            --radius: 12px;
        }

        .section-sub { color: var(--text-muted); font-size: .85rem; margin: -6px 0 20px; max-width: 640px; }
        .section-label { font-weight: 800; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); margin: 0 0 10px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 26px; }
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 12px; }
        .stat-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 18px; border-top: 3px solid var(--text); }
        .stat-card.c-green { border-top-color: var(--green); }
        .stat-card.c-yellow { border-top-color: var(--yellow); }
        .stat-card.c-red { border-top-color: var(--red); }
        .stat-card .num { font-size: 1.85rem; font-weight: 800; line-height: 1; }
        .stat-card .lbl { color: var(--text-muted); font-size: .78rem; font-weight: 600; margin-top: 6px; }

        .charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 22px; }
        @media (max-width: 760px) { .charts-grid { grid-template-columns: 1fr; } }
        .chart-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; }
        .chart-title { font-weight: 700; font-size: .85rem; margin-bottom: 14px; }
        .bar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .bar-row:last-child { margin-bottom: 0; }
        .bar-label { width: 128px; flex-shrink: 0; font-size: .74rem; color: var(--text-muted); font-weight: 600; line-height: 1.2; }
        .bar-track { flex: 1; background: #eef0f4; border-radius: 20px; height: 14px; overflow: hidden; }
        .bar-fill { height: 100%; border-radius: 20px; transition: width .3s ease; }
        .bar-count { width: 20px; text-align: right; font-size: .78rem; font-weight: 700; }
        .donut-wrap { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
        .donut-legend { display: flex; flex-direction: column; gap: 8px; }
        .donut-legend-item { font-size: .78rem; color: var(--text); font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .donut-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
    </style>

    <h1>Bienvenido, <?= htmlspecialchars($usuario['nombre'], ENT_QUOTES, 'UTF-8') ?></h1>
    <div class="section-sub">Vista general de pedidos en curso, calculada en tiempo real desde la base de datos.</div>

    <div class="stats-grid" id="statsGrid"></div>

    <div class="section-label">KPIs de trazabilidad y eficiencia (últimos 30 días)</div>
    <div class="kpi-grid" id="kpiGrid"></div>

    <div class="charts-grid">
        <div class="chart-card">
            <div class="chart-title">Pedidos activos por canal</div>
            <div id="chartCanal"></div>
        </div>
        <div class="chart-card">
            <div class="chart-title">Estado de SLA (Nuevos + Embalando)</div>
            <div id="chartSla"></div>
        </div>
        <div class="chart-card">
            <div class="chart-title">Método de entrega (30 días)</div>
            <div id="chartMetodo"></div>
        </div>
        <div class="chart-card">
            <div class="chart-title">Resultado del despacho (30 días)</div>
            <div id="chartResultado"></div>
        </div>
    </div>

    <script>
    /* ---------- CONFIG (inyectada desde PHP) ---------- */
    const API_BASE = <?= json_encode(baseUrl('api/'), JSON_UNESCAPED_SLASHES) ?>;

    /* ---------- HELPERS (mismo patrón que pedidos.php / despacho.php / facturacion.php) ---------- */
    function escapeHtml(str){
        if(str===null || str===undefined) return '';
        return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function statCard(num, lbl, cls){
        return '<div class="stat-card '+cls+'"><div class="num">'+num+'</div><div class="lbl">'+escapeHtml(lbl)+'</div></div>';
    }
    function formatTiempo(min){
        return min>=60 ? (Math.floor(min/60)+'h '+(min%60)+'m') : (min+'m');
    }

    /* ---------- RENDER: STATS OPERATIVOS + KPIs ---------- */
    function pintarOperacion(op){
        document.getElementById('statsGrid').innerHTML =
            statCard(op.pedidosNuevos, 'Pedidos nuevos', '') +
            statCard(op.enEmbalaje, 'En embalaje', '') +
            statCard(op.porFacturar, 'Por facturar (TSI)', '') +
            statCard(op.urgentes, 'Urgentes (SLA vencido/próximo)', op.urgentes > 0 ? 'c-red' : '') +
            statCard(op.facturadosHoy, 'Facturados hoy', 'c-green');
    }
    function pintarTrazabilidad(tz){
        document.getElementById('kpiGrid').innerHTML =
            statCard(tz.pedidosHoy, 'Pedidos hoy', '') +
            statCard(tz.pedidosSemana, 'Pedidos esta semana', '') +
            statCard(tz.pedidosMes, 'Pedidos este mes', '') +
            statCard(tz.tasaExito+'%', 'Tasa de éxito', 'c-green') +
            statCard(formatTiempo(tz.tiempoPromedioMinutos), 'Tiempo prom. de despacho', '') +
            statCard(tz.ocurrencias, 'Ocurrencias (con observación)', 'c-red');
    }

    /* ---------- RENDER: GRÁFICOS (misma lógica que el prototipo, datos del servidor) ---------- */
    function renderChartCanal(data){
        const max = Math.max(1, ...data.map(d=>d.cantidad));
        document.getElementById('chartCanal').innerHTML = data.map(d=>{
            const pct = Math.round(d.cantidad/max*100);
            return '<div class="bar-row">'+
                '<span class="bar-label">'+escapeHtml(d.nombre)+'</span>'+
                '<div class="bar-track"><div class="bar-fill" style="width:'+pct+'%;background:'+d.color+'"></div></div>'+
                '<span class="bar-count">'+d.cantidad+'</span>'+
            '</div>';
        }).join('');
    }
    function renderChartMetodo(data){
        const max = Math.max(1, ...data.map(d=>d.cantidad));
        document.getElementById('chartMetodo').innerHTML = data.map(d=>{
            const pct = Math.round(d.cantidad/max*100);
            return '<div class="bar-row">'+
                '<span class="bar-label">'+escapeHtml(d.nombre)+'</span>'+
                '<div class="bar-track"><div class="bar-fill" style="width:'+pct+'%;background:'+d.color+'"></div></div>'+
                '<span class="bar-count">'+d.cantidad+'</span>'+
            '</div>';
        }).join('');
    }
    function renderChartSla(data){
        // Mismos umbrales que PedidoRepository::obtenerChartSla() /
        // slaInfo() en pedidos.php — solo cambian los colores/etiquetas de
        // presentación aquí.
        const colors = {verde:'#2fae66', amarillo:'#e8a52a', rojo:'#d6483d'};
        const labels = {verde:'A tiempo', amarillo:'Próximo al corte', rojo:'Urgente / vencido'};
        const counts = {}; data.forEach(d=>{ counts[d.nivel] = d.cantidad; });
        const total = data.reduce((s,d)=>s+d.cantidad, 0);
        const r=40, cx=50, cy=50, circumference=2*Math.PI*r;

        let svgCircles = '<circle r="'+r+'" cx="'+cx+'" cy="'+cy+'" fill="transparent" stroke="#eef0f4" stroke-width="16" />';
        if(total > 0){
            let offset = 0;
            svgCircles += ['verde','amarillo','rojo'].map(nivel=>{
                const frac = counts[nivel]/total;
                const len = frac*circumference;
                const dash = len+' '+(circumference-len);
                const dashoffset = -offset;
                offset += len;
                if(len<=0) return '';
                return '<circle r="'+r+'" cx="'+cx+'" cy="'+cy+'" fill="transparent" stroke="'+colors[nivel]+'" stroke-width="16" stroke-dasharray="'+dash+'" stroke-dashoffset="'+dashoffset+'" stroke-linecap="butt" />';
            }).join('');
        }

        const svg = '<svg viewBox="0 0 100 100" width="110" height="110" style="transform:rotate(-90deg)">'+svgCircles+'</svg>';
        const legend = ['verde','amarillo','rojo'].map(nivel=>
            '<div class="donut-legend-item"><span class="donut-dot" style="background:'+colors[nivel]+'"></span>'+labels[nivel]+': '+counts[nivel]+'</div>'
        ).join('');
        document.getElementById('chartSla').innerHTML = '<div class="donut-wrap">'+svg+'<div class="donut-legend">'+legend+'</div></div>';
    }
    function renderChartResultado(data){
        const colors = {exitoso:'#2fae66', observacion:'#d6483d'};
        const labels = {exitoso:'Exitoso', observacion:'Con observación'};
        const counts = {exitoso:0, observacion:0};
        data.forEach(d=>{ counts[d.resultado] = d.cantidad; });
        const total = counts.exitoso + counts.observacion;
        const r=40, cx=50, cy=50, circumference=2*Math.PI*r;

        let svgCircles = '<circle r="'+r+'" cx="'+cx+'" cy="'+cy+'" fill="transparent" stroke="#eef0f4" stroke-width="16" />';
        if(total > 0){
            let offset = 0;
            ['exitoso','observacion'].forEach(key=>{
                const frac = counts[key]/total;
                const len = frac*circumference;
                if(len > 0){
                    svgCircles += '<circle r="'+r+'" cx="'+cx+'" cy="'+cy+'" fill="transparent" stroke="'+colors[key]+'" stroke-width="16" stroke-dasharray="'+len+' '+(circumference-len)+'" stroke-dashoffset="'+(-offset)+'" />';
                }
                offset += len;
            });
        }

        const svg = '<svg viewBox="0 0 100 100" width="110" height="110" style="transform:rotate(-90deg)">'+svgCircles+'</svg>';
        const legend =
            '<div class="donut-legend-item"><span class="donut-dot" style="background:'+colors.exitoso+'"></span>'+labels.exitoso+': '+counts.exitoso+'</div>'+
            '<div class="donut-legend-item"><span class="donut-dot" style="background:'+colors.observacion+'"></span>'+labels.observacion+': '+counts.observacion+'</div>';
        document.getElementById('chartResultado').innerHTML = '<div class="donut-wrap">'+svg+'<div class="donut-legend">'+legend+'</div></div>';
    }

    /* ---------- CARGA DE DATOS ---------- */
    async function cargarPanel(){
        try {
            const resp = await fetch(API_BASE+'panel_datos.php');
            const data = await resp.json();
            if(!data.ok){ return; }
            pintarOperacion(data.operacion);
            pintarTrazabilidad(data.trazabilidad);
            renderChartCanal(data.chartCanal);
            renderChartSla(data.chartSla);
            renderChartMetodo(data.chartMetodo);
            renderChartResultado(data.chartResultado);
        } catch(e) {
            // Silencioso: si falla un refresco, el panel se queda con los
            // últimos datos válidos hasta el próximo intento a los 30s.
        }
    }

    /* ---------- INIT ---------- */
    cargarPanel();
    setInterval(cargarPanel, 30000);
    </script>
<?php
require __DIR__ . '/core/ui/layout_footer.php';
