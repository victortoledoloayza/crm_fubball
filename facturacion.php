<?php
/**
 * facturacion.php
 *
 * Cola de Facturación: pedidos ya despachados y entregados, esperando su
 * boleta/factura en TSI. Layout/tabla copiados de
 * docs/prototipo-referencia.html (pestaña "Cola de Facturación"), JS
 * conectado a api/facturacion_*.php en vez del array en memoria.
 */

require_once __DIR__ . '/core/bootstrap.php';

Auth::requireLogin();

$tituloPagina = 'Cola de Facturación';
$navActiva    = 'facturacion';
require __DIR__ . '/core/ui/layout_header.php';
?>
    <style>
        :root {
            --border: #e5e7ee;
            --text: #1c2233;
            --text-muted: #6b7280;
            --red: #d6483d;
            --red-dark: #b8392f;
        }

        .btn { border: none; border-radius: 9px; padding: 9px 14px; font-size: .8rem; font-weight: 700; cursor: pointer; font-family: inherit; }
        .btn-outline { background: #f2f3f7; color: var(--text); }
        .btn-outline:hover { background: #e7e9f0; }
        .btn-primary { background: var(--red); color: #fff; }
        .btn-primary:hover { background: var(--red-dark); }
        .btn-dark { background: var(--text); color: #fff; }
        .btn-dark:hover { background: #000000; }
        a.btn-dark { display: inline-block; text-decoration: none; }

        .section-sub { color: var(--text-muted); font-size: .85rem; margin: 0 0 20px; max-width: 680px; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }

        .table-wrap { background: #fff; border: 1px solid var(--border); border-radius: 14px; overflow: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .83rem; min-width: 900px; }
        th { text-align: left; background: #f7f8fa; padding: 11px 14px; font-size: .68rem; text-transform: uppercase; color: var(--text-muted); letter-spacing: .04em; border-bottom: 1px solid var(--border); white-space: nowrap; }
        td { padding: 11px 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        .row-actions { display: flex; gap: 6px; white-space: nowrap; }
        .amount { font-weight: 700; }

        .channel-badge { font-size: .64rem; font-weight: 800; color: #fff; padding: 3px 9px; border-radius: 6px; text-transform: uppercase; letter-spacing: .03em; white-space: nowrap; }
        .ticket-id { font-size: .78rem; color: var(--text-muted); font-weight: 700; white-space: nowrap; text-decoration: none; border-bottom: 1px dashed var(--text-muted); }
        .ticket-id:hover { color: var(--text); border-bottom-color: var(--text); }
        .empty { text-align: center; color: var(--text-muted); padding: 26px 10px; font-size: .82rem; }

        #toast { position: fixed; bottom: 22px; right: 22px; display: flex; flex-direction: column; gap: 8px; z-index: 999; max-width: 320px; }
        .toast-item { background: #1c2233; color: #fff; padding: 12px 16px; border-radius: 10px; font-size: .82rem; font-weight: 600; box-shadow: 0 6px 16px rgba(0,0,0,.25); animation: toastIn .2s ease; }
        @keyframes toastIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    </style>

    <div class="section-sub">
        Pedidos ya despachados y entregados, esperando su boleta/factura en TSI. Copia los datos de uno o exporta
        el lote completo del día.
    </div>

    <div class="toolbar">
        <div style="font-weight:700;font-size:.85rem;" id="factCount">0 pedidos por facturar</div>
        <a class="btn btn-dark" href="<?= htmlspecialchars(baseUrl('api/facturacion_exportar_csv.php'), ENT_QUOTES, 'UTF-8') ?>">⬇️ Exportar Excel/CSV del día</a>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Orden</th><th>Canal</th><th>Cliente</th><th>DNI</th><th>Dirección</th><th>Monto</th><th>Límite</th><th>Responsables</th><th>Acciones</th></tr>
            </thead>
            <tbody id="factBody"></tbody>
        </table>
    </div>

    <div id="toast"></div>

    <script>
    /* ---------- CONFIG (inyectada desde PHP) ---------- */
    const API_BASE = <?= json_encode(baseUrl('api/'), JSON_UNESCAPED_SLASHES) ?>;
    const CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;

    let pedidosCache = [];

    /* ---------- HELPERS (mismo patrón que pedidos.php / despacho.php) ---------- */
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
    function formatDeadlineDate(d){
        const day = d.toLocaleDateString('es-PE',{day:'2-digit',month:'2-digit'});
        const time = d.toLocaleTimeString('es-PE',{hour:'2-digit',minute:'2-digit',hour12:true});
        return day+' '+time;
    }
    // Copia al portapapeles con fallback — texto plano, no pasa por
    // innerHTML, así que no hace falta escapeHtml aquí.
    function copyText(text){
        if(navigator.clipboard && window.isSecureContext){
            navigator.clipboard.writeText(text).then(()=>toast('📋 Datos copiados al portapapeles')).catch(()=>fallbackCopy(text));
        } else {
            fallbackCopy(text);
        }
    }
    function fallbackCopy(text){
        const ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.focus(); ta.select();
        try {
            document.execCommand('copy');
            toast('📋 Datos copiados al portapapeles');
        } catch(e) {
            toast('No se pudo copiar automáticamente');
        }
        document.body.removeChild(ta);
    }

    // Adapta una fila cruda de api/facturacion_listar.php a la forma que
    // usan las funciones de render de abajo.
    function mapPedido(row){
        return {
            id: row.id,
            codigoOrden: row.codigo_orden,
            channel: {codigo: row.canal_codigo, nombre: row.canal_nombre, color: row.canal_color},
            customer: row.cliente_nombre,
            dni: row.cliente_dni,
            address: row.cliente_direccion,
            amount: parseFloat(row.monto_total),
            deadline: new Date(String(row.fecha_limite).replace(' ', 'T')),
            items: row.items.map(it => ({
                product: it.producto_nombre,
                variant: it.variante || '',
                sku: it.sku || '',
                qty: parseInt(it.cantidad, 10)
            })),
            responsables: {
                embalaje: row.resp_embalaje_nombre || null,
                verificacion: row.resp_verificacion_nombre || null,
                despacho: row.resp_despacho_nombre || null
            }
        };
    }

    /* ---------- RENDER ---------- */
    function responsablesCompactoHTML(o){
        const partes = [];
        if(o.responsables.embalaje) partes.push('📦 '+escapeHtml(o.responsables.embalaje));
        if(o.responsables.verificacion) partes.push('🔍 '+escapeHtml(o.responsables.verificacion));
        if(o.responsables.despacho) partes.push('🚚 '+escapeHtml(o.responsables.despacho));
        return partes.length ? partes.join('<br>') : '—';
    }
    function filaHTML(o){
        return '<tr id="fila-'+o.id+'">'+
            '<td><a class="ticket-id" href="'+labelUrl(o.codigoOrden)+'" target="_blank" rel="noopener">#'+escapeHtml(o.codigoOrden)+' 🔗</a></td>'+
            '<td><span class="channel-badge" style="background:'+o.channel.color+'">'+escapeHtml(o.channel.nombre)+'</span></td>'+
            '<td>'+escapeHtml(o.customer)+'</td>'+
            '<td>'+escapeHtml(o.dni || '—')+'</td>'+
            '<td>'+escapeHtml(o.address || '—')+'</td>'+
            '<td class="amount">S/ '+o.amount.toFixed(2)+'</td>'+
            '<td>'+formatDeadlineDate(o.deadline)+'</td>'+
            '<td style="font-size:.76rem;">'+responsablesCompactoHTML(o)+'</td>'+
            '<td class="row-actions">'+
                '<button type="button" class="btn btn-outline" onclick="regresarFase('+o.id+')">← Regresar</button>'+
                '<button type="button" class="btn btn-outline" onclick="copiarDatos('+o.id+')">📋 Copiar</button>'+
                '<button type="button" class="btn btn-primary" onclick="marcarFacturadoUI('+o.id+')">✅ Facturado</button>'+
            '</td>'+
        '</tr>';
    }
    function renderFacturacion(){
        document.getElementById('factCount').textContent = pedidosCache.length+' pedido'+(pedidosCache.length===1?'':'s')+' por facturar';
        document.getElementById('factBody').innerHTML = pedidosCache.length
            ? pedidosCache.map(filaHTML).join('')
            : '<tr><td colspan="9" class="empty">No hay pedidos esperando factura.</td></tr>';
    }

    /* ---------- CARGA DE DATOS ---------- */
    async function cargarFacturacion(){
        try {
            const resp = await fetch(API_BASE+'facturacion_listar.php');
            const data = await resp.json();
            if(!data.ok){ toast('⚠️ '+(data.error||'No se pudo cargar la cola de facturación.')); return; }
            pedidosCache = data.pedidos.map(mapPedido);
            renderFacturacion();
        } catch(e) {
            toast('⚠️ Error de red al cargar la cola de facturación.');
        }
    }

    /* ---------- ACCIONES ---------- */
    // Arma el texto a partir de los datos ya cargados en JS — sin pedir
    // nada más al servidor.
    function copiarDatos(id){
        const o = pedidosCache.find(x=>x.id===id);
        if(!o) return;
        const productosTxt = o.items.map(it=>{
            let desc = it.qty+'x '+it.product;
            if(it.variant) desc += ' ('+it.variant+')';
            if(it.sku) desc += ' SKU '+it.sku;
            return desc;
        }).join('; ');
        const text = 'Cliente: '+o.customer+
            '\nDNI: '+(o.dni||'—')+
            '\nDirección: '+(o.address||'—')+
            '\nProductos: '+productosTxt+
            '\nMonto: S/ '+o.amount.toFixed(2)+
            '\nCanal: '+o.channel.nombre+
            '\nN° Orden: '+o.codigoOrden;
        copyText(text);
    }
    // Todo pedido en esta cola está en estado 'facturacion_pendiente' (ver
    // PedidoRepository::listarFacturacionPendiente()), así que se hardcodea
    // acá igual que en despacho.php.
    async function regresarFase(id){
        const o = pedidosCache.find(x=>x.id===id);
        const codigo = o ? o.codigoOrden : ('#'+id);
        if(!confirm('¿Regresar el pedido '+codigo+' a Despacho? Esta acción queda registrada en el historial.')) return;

        try {
            const resp = await fetch(API_BASE+'pedidos_retroceder.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({pedido_id: id, estado_actual: 'facturacion_pendiente', csrf_token: CSRF_TOKEN})
            });
            const data = await resp.json();
            if(!data.ok){
                toast('⚠️ '+(data.error||'No se pudo regresar el pedido.'));
                return;
            }
            // Misma lógica que marcarFacturadoUI: la fila sale de esta cola,
            // así que se quita de inmediato sin esperar el refresco de 30s.
            pedidosCache = pedidosCache.filter(o=>o.id!==id);
            renderFacturacion();
            toast('↩️ Pedido regresado a Despacho');
        } catch(e) {
            toast('⚠️ Error de red al regresar el pedido.');
        }
    }
    async function marcarFacturadoUI(id){
        try {
            const resp = await fetch(API_BASE+'facturacion_marcar.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({pedido_id: id, csrf_token: CSRF_TOKEN})
            });
            const data = await resp.json();
            if(!data.ok){
                toast('⚠️ '+(data.error||'No se pudo marcar como facturado.'));
                return;
            }
            // La fila desaparece de inmediato, sin esperar el refresco de 30s.
            pedidosCache = pedidosCache.filter(o=>o.id!==id);
            renderFacturacion();
            toast('🧾 Pedido facturado y archivado');
        } catch(e) {
            toast('⚠️ Error de red.');
        }
    }

    /* ---------- INIT ---------- */
    cargarFacturacion();
    setInterval(cargarFacturacion, 30000);
    </script>
<?php
require __DIR__ . '/core/ui/layout_footer.php';
