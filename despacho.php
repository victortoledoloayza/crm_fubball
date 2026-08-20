<?php
/**
 * despacho.php
 *
 * Cola de Despacho: pedidos ya verificados, esperando salir físicamente
 * (delivery en moto, recojo en tienda, envío a provincia, o punto de
 * acopio del marketplace). Layout/tabla copiados de
 * docs/prototipo-referencia.html (pestaña "Cola de Despacho"), JS
 * conectado a api/despacho_*.php en vez del array en memoria.
 */

require_once __DIR__ . '/core/bootstrap.php';

Auth::requireLogin();

$tituloPagina = 'Cola de Despacho';
$navActiva    = 'despacho';
require __DIR__ . '/core/ui/layout_header.php';
?>
    <style>
        :root {
            --border: #e5e7ee;
            --text: #1c2233;
            --text-muted: #6b7280;
            --red: #d6483d;
            --red-dark: #b8392f;
            --green: #2fae66;
            --yellow-text: #92660a;
        }

        .btn { border: none; border-radius: 9px; padding: 9px 14px; font-size: .8rem; font-weight: 700; cursor: pointer; font-family: inherit; }
        .btn-outline { background: #f2f3f7; color: var(--text); }
        .btn-outline:hover { background: #e7e9f0; }
        .btn-primary { background: var(--red); color: #fff; }
        .btn-primary:hover { background: var(--red-dark); }
        .btn[disabled] { opacity: .45; cursor: not-allowed; }
        .btn[disabled]:hover { background: var(--red); }

        .section-sub { color: var(--text-muted); font-size: .85rem; margin: 0 0 20px; max-width: 680px; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }

        .table-wrap { background: #fff; border: 1px solid var(--border); border-radius: 14px; overflow: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .83rem; min-width: 820px; }
        th { text-align: left; background: #f7f8fa; padding: 11px 14px; font-size: .68rem; text-transform: uppercase; color: var(--text-muted); letter-spacing: .04em; border-bottom: 1px solid var(--border); white-space: nowrap; }
        td { padding: 11px 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        .row-actions { display: flex; gap: 6px; white-space: nowrap; }

        .channel-badge { font-size: .64rem; font-weight: 800; color: #fff; padding: 3px 9px; border-radius: 6px; text-transform: uppercase; letter-spacing: .03em; white-space: nowrap; }
        .method-badge { display: inline-flex; align-items: center; gap: 4px; font-size: .72rem; font-weight: 700; color: #fff; padding: 4px 10px; border-radius: 6px; white-space: nowrap; }
        .ticket-id { font-size: .78rem; color: var(--text-muted); font-weight: 700; white-space: nowrap; text-decoration: none; border-bottom: 1px dashed var(--text-muted); }
        .ticket-id:hover { color: var(--text); border-bottom-color: var(--text); }
        .empty { text-align: center; color: var(--text-muted); padding: 26px 10px; font-size: .82rem; }

        #toast { position: fixed; bottom: 22px; right: 22px; display: flex; flex-direction: column; gap: 8px; z-index: 999; max-width: 320px; }
        .toast-item { background: #1c2233; color: #fff; padding: 12px 16px; border-radius: 10px; font-size: .82rem; font-weight: 600; box-shadow: 0 6px 16px rgba(0,0,0,.25); animation: toastIn .2s ease; }
        @keyframes toastIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    </style>

    <div class="section-sub">
        Pedidos ya verificados, esperando salir físicamente: delivery en moto, recojo del cliente en tienda, envío
        por agencia a provincia, o entrega en el punto de acopio del marketplace. Al confirmar la entrega, el
        pedido pasa a Facturación.
    </div>

    <div class="toolbar">
        <div style="font-weight:700;font-size:.85rem;" id="despachoCount">0 pedidos por despachar</div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Orden</th><th>Canal</th><th>Cliente</th><th>Dirección</th><th>Método</th><th>Responsable</th><th>Estado</th><th>Acciones</th></tr>
            </thead>
            <tbody id="despachoBody"></tbody>
        </table>
    </div>

    <div id="toast"></div>

    <script>
    /* ---------- CONFIG (inyectada desde PHP) ---------- */
    const API_BASE = <?= json_encode(baseUrl('api/'), JSON_UNESCAPED_SLASHES) ?>;
    const CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;

    let pedidosCache = [];

    /* ---------- HELPERS (mismo patrón que pedidos.php) ---------- */
    function escapeHtml(str){
        if(str===null || str===undefined) return '';
        return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function toast(msg){
        const box = document.getElementById('toast');
        const el = document.createElement('div');
        el.className = 'toast-item';
        el.textContent = msg;
        box.appendChild(el);
        setTimeout(()=>{ el.style.opacity='0'; el.style.transition='opacity .3s'; setTimeout(()=>el.remove(),300); }, 3200);
    }
    function labelUrl(codigoOrden){
        return 'https://tlnegocios.com/fubball/etiquetas/'+codigoOrden+'.pdf';
    }

    // Adapta una fila cruda de api/despacho_listar.php (columnas de pedidos
    // + canal + método de despacho, ambos desde sus tablas reales, + nombre
    // del responsable) a la forma que usan las funciones de render de abajo.
    function mapPedido(row){
        return {
            id: row.id,
            codigoOrden: row.codigo_orden,
            channel: {codigo: row.canal_codigo, nombre: row.canal_nombre, color: row.canal_color},
            customer: row.cliente_nombre,
            address: row.cliente_direccion,
            metodo: row.metodo_codigo ? {codigo: row.metodo_codigo, nombre: row.metodo_nombre, color: row.metodo_color} : null,
            responsable: row.resp_despacho_nombre || null,
            motorizadoCoordinado: parseInt(row.motorizado_coordinado, 10) === 1
        };
    }

    /* ---------- RENDER ---------- */
    function metodoBadgeHTML(o){
        if(!o.metodo) return '—';
        return '<span class="method-badge" style="background:'+o.metodo.color+'">'+escapeHtml(o.metodo.nombre)+'</span>';
    }
    function estadoHTML(o){
        if(o.metodo && o.metodo.codigo==='moto_delivery'){
            return o.motorizadoCoordinado
                ? '<span style="color:var(--green);font-weight:700;">✅ Motorizado coordinado</span>'
                : '<span style="color:var(--yellow-text);font-weight:700;">🕐 Pendiente coordinar</span>';
        }
        return '<span style="color:var(--text-muted);">—</span>';
    }
    // El texto de cada botón depende del método real (moto_delivery,
    // recojo_tienda, envio_provincia, punto_acopio), pero TODOS llaman al
    // mismo endpoint de fondo (api/despacho_marcar_entregado.php) — el
    // backend ya valida las reglas, aquí solo evitamos mostrar un botón que
    // sabemos que va a fallar (moto sin coordinar).
    function accionesDespachoHTML(o){
        if(o.metodo && o.metodo.codigo==='moto_delivery'){
            if(!o.motorizadoCoordinado){
                return '<button type="button" class="btn btn-outline" onclick="coordinarMotorizado('+o.id+')">📞 Coordinar motorizado</button>'+
                       '<button type="button" class="btn btn-primary" disabled title="Coordina el motorizado antes de marcar como entregado">🛵 Marcar entregado</button>';
            }
            return '<button type="button" class="btn btn-primary" onclick="marcarEntregado('+o.id+')">🛵 Marcar entregado</button>';
        }
        if(o.metodo && o.metodo.codigo==='recojo_tienda'){
            return '<button type="button" class="btn btn-primary" onclick="marcarEntregado('+o.id+')">🏬 Marcar recogido</button>';
        }
        if(o.metodo && o.metodo.codigo==='envio_provincia'){
            return '<button type="button" class="btn btn-primary" onclick="marcarEntregado('+o.id+')">📦 Marcar entregado en agencia</button>';
        }
        if(o.metodo && o.metodo.codigo==='punto_acopio'){
            return '<button type="button" class="btn btn-primary" onclick="marcarEntregado('+o.id+')">📍 Marcar entregado en '+escapeHtml(o.channel.nombre)+'</button>';
        }
        return '<button type="button" class="btn btn-primary" onclick="marcarEntregado('+o.id+')">✅ Marcar entregado</button>';
    }
    function filaHTML(o){
        return '<tr id="fila-'+o.id+'">'+
            '<td><a class="ticket-id" href="'+labelUrl(o.codigoOrden)+'" target="_blank" rel="noopener">#'+escapeHtml(o.codigoOrden)+' 🔗</a></td>'+
            '<td><span class="channel-badge" style="background:'+o.channel.color+'">'+escapeHtml(o.channel.nombre)+'</span></td>'+
            '<td>'+escapeHtml(o.customer)+'</td>'+
            '<td>'+escapeHtml(o.address || '—')+'</td>'+
            '<td>'+metodoBadgeHTML(o)+'</td>'+
            '<td>'+escapeHtml(o.responsable || '—')+'</td>'+
            '<td>'+estadoHTML(o)+'</td>'+
            '<td class="row-actions">'+accionesDespachoHTML(o)+'</td>'+
        '</tr>';
    }
    function renderDespacho(){
        document.getElementById('despachoCount').textContent = pedidosCache.length+' pedido'+(pedidosCache.length===1?'':'s')+' por despachar';
        document.getElementById('despachoBody').innerHTML = pedidosCache.length
            ? pedidosCache.map(filaHTML).join('')
            : '<tr><td colspan="8" class="empty">No hay pedidos esperando despacho.</td></tr>';
    }

    /* ---------- CARGA DE DATOS ---------- */
    async function cargarDespacho(){
        try {
            const resp = await fetch(API_BASE+'despacho_listar.php');
            const data = await resp.json();
            if(!data.ok){ toast('⚠️ '+(data.error||'No se pudo cargar la cola de despacho.')); return; }
            pedidosCache = data.pedidos.map(mapPedido);
            renderDespacho();
        } catch(e) {
            toast('⚠️ Error de red al cargar la cola de despacho.');
        }
    }

    /* ---------- ACCIONES ---------- */
    async function postAccion(url, pedidoId){
        try {
            const resp = await fetch(API_BASE+url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({pedido_id: pedidoId, csrf_token: CSRF_TOKEN})
            });
            const data = await resp.json();
            if(!data.ok){
                toast('⚠️ '+(data.error||'No se pudo completar la acción.'));
                return false;
            }
            return true;
        } catch(e) {
            toast('⚠️ Error de red.');
            return false;
        }
    }
    async function coordinarMotorizado(id){
        const ok = await postAccion('despacho_coordinar_motorizado.php', id);
        if(ok){
            toast('📞 Motorizado coordinado');
            cargarDespacho();
        }
    }
    async function marcarEntregado(id){
        const ok = await postAccion('despacho_marcar_entregado.php', id);
        if(ok){
            toast('✅ Pedido entregado — pasa a Cola de Facturación');
            cargarDespacho();
        }
    }

    /* ---------- INIT ---------- */
    cargarDespacho();
    setInterval(cargarDespacho, 30000);
    </script>
<?php
require __DIR__ . '/core/ui/layout_footer.php';
