<?php
/**
 * pedidos.php
 *
 * Tablero KDS: layout, colores, tarjetas, semáforo SLA, miniaturas y
 * modal de detalle copiados de docs/prototipo-referencia.html (pestaña
 * "kds"). El array `orders` en memoria del prototipo fue reemplazado por
 * fetch() a api/pedidos_listar.php / pedidos_avanzar.php / pedidos_detalle.php
 * / usuarios_listar.php — ver comentarios en el <script> más abajo.
 */

require_once __DIR__ . '/core/bootstrap.php';

Auth::requireLogin();

$pdo = Database::getConnection();

$canales = $pdo->query('SELECT codigo, nombre, color_hex FROM canales WHERE activo = 1 ORDER BY id')->fetchAll();
$channelMetaPhp = [];
foreach ($canales as $c) {
    $channelMetaPhp[$c['codigo']] = ['label' => $c['nombre'], 'color' => $c['color_hex']];
}

$mensajeExito = ($_GET['ok'] ?? '') === 'creado' ? 'Pedido creado correctamente.' : '';

$tituloPagina = 'Tablero KDS';
$navActiva    = 'tablero';
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
            --yellow: #e8a52a;
            --yellow-text: #92660a;
            --card-bg: #ffffff;
            --radius: 12px;
        }

        .btn { border: none; border-radius: 9px; padding: 9px 14px; font-size: .8rem; font-weight: 700; cursor: pointer; font-family: inherit; }
        .btn-outline { background: #f2f3f7; color: var(--text); }
        .btn-outline:hover { background: #e7e9f0; }
        .btn-primary { background: var(--red); color: #fff; }
        .btn-primary:hover { background: var(--red-dark); }
        a.btn-primary { display: inline-block; text-decoration: none; }

        .toolbar-superior { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 4px; }
        .section-sub { color: var(--text-muted); font-size: .85rem; margin: 0 0 20px; max-width: 640px; }
        .mensaje-exito { background: #eafaf0; border: 1px solid #b7ecc9; color: #166534; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 18px; }

        .filters { display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap; }
        .chip { border: 1px solid var(--border); background: #fff; padding: 6px 14px; border-radius: 20px; font-size: .8rem; font-weight: 700; cursor: pointer; color: var(--text-muted); }
        .chip.active { background: var(--text); color: #fff; border-color: var(--text); }

        .board { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; align-items: start; }
        @media (max-width: 1050px) { .board { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 700px) { .board { grid-template-columns: 1fr; } }
        .column { background: #e9eaf0; border-radius: 14px; padding: 14px; min-height: 160px; }
        .column-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-weight: 800; font-size: .87rem; padding: 0 4px; }
        .column-count { background: #fff; border-radius: 20px; padding: 1px 9px; font-size: .72rem; font-weight: 700; }
        .cards { display: flex; flex-direction: column; gap: 12px; }

        .ticket { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,.05); border: 1px solid var(--border); }
        .ticket-sla { height: 5px; }
        .ticket-sla.green { background: var(--green); }
        .ticket-sla.yellow { background: var(--yellow); }
        .ticket-sla.red { background: var(--red); }
        .ticket-body { padding: 13px 15px; }
        .ticket-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 9px; gap: 8px; }
        .channel-badge { font-size: .64rem; font-weight: 800; color: #fff; padding: 3px 9px; border-radius: 6px; text-transform: uppercase; letter-spacing: .03em; white-space: nowrap; }
        .ticket-id { font-size: .7rem; color: var(--text-muted); font-weight: 700; white-space: nowrap; text-decoration: none; border-bottom: 1px dashed var(--text-muted); }
        .ticket-id:hover { color: var(--text); border-bottom-color: var(--text); }
        .ticket-top-right { display: flex; align-items: center; gap: 8px; }
        .btn-delete-icon { background: none; border: none; padding: 2px 4px; font-size: .8rem; line-height: 1; cursor: pointer; opacity: .5; }
        .btn-delete-icon:hover { opacity: 1; }

        .ticket-product-row { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 9px; }
        .ticket-product-info { flex: 1; min-width: 0; }
        .item-thumb {
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 10px; flex-shrink: 0; overflow: hidden; position: relative;
            background: #f2f3f7; border: 1px solid var(--border);
        }
        .item-thumb img { width: 100%; height: 100%; object-fit: contain; display: block; transition: transform .18s ease; }
        a.item-thumb { cursor: pointer; }
        a.item-thumb:hover img { transform: scale(1.15); }
        .item-thumb-badge {
            position: absolute; bottom: 3px; right: 3px; width: 18px; height: 18px;
            display: flex; align-items: center; justify-content: center;
            background: rgba(15,18,30,.65); color: #fff; border-radius: 50%;
            font-size: 10px; opacity: 0; transition: opacity .15s ease;
        }
        a.item-thumb:hover .item-thumb-badge { opacity: 1; }
        .item-thumb--sinfoto { color: var(--text-muted); font-size: 1.3rem; cursor: default; }
        .item-thumb--sinfoto .item-thumb-placeholder-icon { opacity: .55; }
        .item-thumbs-stack { display: flex; align-items: center; flex-shrink: 0; }
        .item-thumbs-stack .item-thumb { margin-left: -12px; border: 2px solid #fff; border-radius: 8px; }
        .item-thumbs-stack .item-thumb:first-child { margin-left: 0; }
        .item-thumb.more { background: #e5e7ee; color: var(--text-muted); font-size: .68rem; font-weight: 800; }
        .detail-link { background: none; border: none; padding: 0; margin-top: 3px; color: var(--red); font-size: .76rem; font-weight: 700; cursor: pointer; text-decoration: underline; font-family: inherit; }
        .detail-link:hover { color: var(--red-dark); }

        .ticket-product { font-weight: 800; font-size: .92rem; margin-bottom: 1px; }
        .ticket-variant { color: var(--text-muted); font-size: .78rem; margin-bottom: 0; }
        .ticket-flag { display: inline-block; background: #fff2df; color: #9a6408; font-size: .68rem; font-weight: 800; padding: 3px 8px; border-radius: 6px; margin-bottom: 9px; }
        .resp-trail { font-size: .72rem; color: var(--text-muted); font-weight: 600; margin-bottom: 8px; }
        .ticket-deadline { font-size: .76rem; font-weight: 800; display: flex; align-items: center; gap: 5px; margin-bottom: 10px; }
        .ticket-deadline.green { color: var(--green); }
        .ticket-deadline.yellow { color: var(--yellow-text); }
        .ticket-deadline.red { color: var(--red); }
        .resp-select-row { margin-bottom: 10px; }
        .resp-select-label { display: block; font-size: .66rem; color: var(--text-muted); font-weight: 700; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .03em; }
        .resp-select { width: 100%; padding: 7px 8px; border: 1px solid var(--border); border-radius: 8px; font-size: .78rem; font-family: inherit; background: #fff; color: var(--text); }
        .ticket-actions { display: flex; gap: 7px; }
        .ticket-actions .btn-primary { flex: 1; }
        .empty { text-align: center; color: var(--text-muted); padding: 26px 10px; font-size: .82rem; }

        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(15,18,30,.55); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
        .modal-overlay.open { display: flex; }
        .modal-card { background: #fff; border-radius: 16px; max-width: 480px; width: 100%; max-height: 80vh; overflow-y: auto; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 18px; border-bottom: 1px solid var(--border); position: sticky; top: 0; background: #fff; }
        .modal-header h3 { margin: 0; font-size: .95rem; }
        .modal-close { background: #f2f3f7; border: none; width: 28px; height: 28px; border-radius: 50%; font-size: 1rem; cursor: pointer; color: var(--text-muted); }
        .modal-body { padding: 8px 18px 18px; }
        .modal-item-row { display: flex; gap: 14px; align-items: flex-start; padding: 14px 0; border-bottom: 1px solid var(--border); }
        .modal-item-row:last-child { border-bottom: none; }
        .modal-item-thumb {
            width: 150px; height: 150px; flex-shrink: 0; border-radius: 12px; overflow: hidden;
            background: #f2f3f7; border: 1px solid var(--border);
            display: flex; align-items: center; justify-content: center;
        }
        .modal-item-thumb img { width: 100%; height: 100%; object-fit: contain; display: block; cursor: zoom-in; }
        .modal-item-thumb--sinfoto { color: var(--text-muted); font-size: 2.2rem; }
        .modal-item-col { display: flex; flex-direction: column; gap: 7px; min-width: 0; flex: 1; }
        .modal-item-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
        .modal-item-name { font-weight: 700; font-size: .86rem; }
        .modal-item-meta { color: var(--text-muted); font-size: .78rem; margin-top: 2px; }
        .modal-item-qty { font-weight: 800; font-size: .85rem; background: #f2f3f7; border-radius: 8px; padding: 4px 10px; flex-shrink: 0; }
        .modal-item-link {
            align-self: flex-start; font-size: .74rem; font-weight: 700; color: var(--red);
            text-decoration: none; border: 1px solid var(--border); border-radius: 6px; padding: 4px 9px;
        }
        .modal-item-link:hover { background: #f2f3f7; color: var(--red-dark); }

        .lightbox-overlay {
            display: none; position: fixed; inset: 0; background: rgba(10,12,20,.9); z-index: 2000;
            align-items: center; justify-content: center; flex-direction: column; padding: 24px; cursor: zoom-out;
        }
        .lightbox-overlay.open { display: flex; }
        .lightbox-overlay img { max-width: 90vw; max-height: 78vh; object-fit: contain; border-radius: 8px; box-shadow: 0 20px 60px rgba(0,0,0,.5); cursor: default; }
        .lightbox-close {
            position: absolute; top: 18px; right: 22px; width: 36px; height: 36px; border-radius: 50%;
            border: none; background: rgba(255,255,255,.12); color: #fff; font-size: 1.1rem; cursor: pointer;
        }
        .lightbox-close:hover { background: rgba(255,255,255,.22); }
        .lightbox-caption { margin-top: 14px; color: #e8eaf0; font-size: .85rem; font-weight: 600; text-align: center; }

        #toast { position: fixed; bottom: 22px; right: 22px; display: flex; flex-direction: column; gap: 8px; z-index: 999; max-width: 320px; }
        .toast-item { background: #1c2233; color: #fff; padding: 12px 16px; border-radius: 10px; font-size: .82rem; font-weight: 600; box-shadow: 0 6px 16px rgba(0,0,0,.25); animation: toastIn .2s ease; }
        @keyframes toastIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    </style>

    <div class="toolbar-superior">
        <div class="section-sub">
            Flujo: Nuevo → Embalando → Verificación → Despacho → Facturación. Cada cambio de fase requiere elegir un
            responsable. El semáforo (🟢🟡🔴) se calcula contra la hora límite de despacho. El número de orden (#) es
            un link directo a la etiqueta PDF.
        </div>
        <a class="btn btn-primary" href="<?= htmlspecialchars(baseUrl('pedidos_nuevo.php'), ENT_QUOTES, 'UTF-8') ?>">+ Nuevo pedido</a>
    </div>

    <?php if ($mensajeExito !== ''): ?>
        <div class="mensaje-exito"><?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="filters" id="channelFilters"></div>
    <div class="board">
        <div class="column">
            <div class="column-header"><span>🆕 Nuevos</span><span class="column-count" id="countNuevos">0</span></div>
            <div class="cards" id="colNuevos"></div>
        </div>
        <div class="column">
            <div class="column-header"><span>📦 Embalando</span><span class="column-count" id="countEmbalando">0</span></div>
            <div class="cards" id="colEmbalando"></div>
        </div>
        <div class="column">
            <div class="column-header"><span>🔍 Verificación</span><span class="column-count" id="countVerificacion">0</span></div>
            <div class="cards" id="colVerificacion"></div>
        </div>
    </div>

    <div id="toast"></div>

    <div class="modal-overlay" id="detailModalOverlay" onclick="if(event.target===this) cerrarDetalle()">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="detailModalTitle">Detalle del pedido</h3>
                <button class="modal-close" onclick="cerrarDetalle()" title="Cerrar">✕</button>
            </div>
            <div class="modal-body" id="detailModalBody"></div>
        </div>
    </div>

    <div class="lightbox-overlay" id="lightboxOverlay" onclick="if(event.target===this) cerrarLightbox()">
        <button class="lightbox-close" onclick="cerrarLightbox()" title="Cerrar">✕</button>
        <img id="lightboxImg" src="" alt="">
        <div class="lightbox-caption" id="lightboxCaption"></div>
    </div>

    <script>
    /* ---------- CONFIG (inyectada desde PHP) ---------- */
    const API_BASE = <?= json_encode(baseUrl('api/'), JSON_UNESCAPED_SLASHES) ?>;
    const CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
    // Controla si se pinta el botón "Eliminar" en las tarjetas — el
    // servidor SIEMPRE vuelve a validar el rol en api/pedidos_eliminar.php,
    // esto solo evita mostrar el botón a quien no puede usarlo.
    const ES_ADMIN = <?= json_encode(($usuarioActual['rol'] ?? null) === 'admin') ?>;
    // channelMeta viene de la tabla `canales` real (antes era un objeto
    // hardcodeado en el prototipo) — así nunca se desincroniza con la BD.
    const channelMeta = <?= json_encode($channelMetaPhp, JSON_UNESCAPED_UNICODE) ?>;

    /* ---------- ESTADO ---------- */
    let pedidosCache = [];
    let usuariosCache = [];
    let metodosCache = [];
    let currentChannelFilter = 'ALL';

    // Fases del flujo de despacho: de qué estado a cuál, qué responsable se
    // pide en la transición, y el texto del botón/label del select. Debe
    // coincidir con PedidoRepository::TRANSICIONES en el backend.
    const FASES = {
        nuevo:        {next:'embalando',    key:'embalaje',     label:'Embalar ➔',           selectLabel:'Responsable de embalaje'},
        embalando:    {next:'verificacion', key:'verificacion', label:'Verificar ➔',         selectLabel:'Responsable de verificación'},
        verificacion: {next:'despacho',     key:'despacho',     label:'✅ Enviar a Despacho', selectLabel:'Responsable de despacho'}
    };

    /* ---------- HELPERS ---------- */
    function escapeHtml(str){
        if(str===null || str===undefined) return '';
        return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function formatRemaining(diffH){
        if(diffH>=24){const d=Math.floor(diffH/24); const h=Math.round(diffH-d*24); return d+'d '+h+'h';}
        const h=Math.floor(diffH); const m=Math.round((diffH-h)*60);
        return h>0 ? (h+'h '+m+'m') : (m+'m');
    }
    // El semáforo SLA se calcula 100% en el navegador, contra la hora real
    // del cliente (new Date()) — ya no hay "hora simulada".
    function slaInfo(o){
        const diffMs = o.deadline - new Date();
        const diffH = diffMs/3600000;
        if(diffH < 0) return {level:'red', text:'VENCIDO ⚠️'};
        if(diffH < 2) return {level:'yellow', text: formatRemaining(diffH)+' restantes'};
        return {level:'green', text: formatRemaining(diffH)+' restantes'};
    }
    function toast(msg){
        const box = document.getElementById('toast');
        const el = document.createElement('div');
        el.className = 'toast-item';
        el.textContent = msg;
        box.appendChild(el);
        setTimeout(()=>{ el.style.opacity='0'; el.style.transition='opacity .3s'; setTimeout(()=>el.remove(),300); }, 3200);
    }
    // Fotos reales de fubball.com. Como los productos de esta demo/carga
    // manual no siempre existen 1:1 en el catálogo real, se usa la foto de
    // la categoría/banda deportiva más cercana según palabras clave del
    // nombre — igual que en el prototipo. En producción (Fase 7, vía
    // Shopify) esto se reemplaza por la imageUrl real de cada producto.
    const CATEGORY_PHOTOS = {
        futbol:       'https://fubball.com/cdn/shop/files/para_el_banner_principal_Vista_de_escritorio_1920_x_700_vista_movil_700_x_1200_13.png?v=1776893251&width=200',
        basquet:      'https://fubball.com/cdn/shop/files/para_el_banner_principal_Vista_de_escritorio_1920_x_700_vista_movil_700_x_1200_16.png?v=1776893887&width=200',
        tenis:        'https://fubball.com/cdn/shop/files/para_el_banner_principal_Vista_de_escritorio_1920_x_700_vista_movil_700_x_1200_11.png?v=1776892799&width=200',
        box:          'https://fubball.com/cdn/shop/files/para_el_banner_principal_Vista_de_escritorio_1920_x_700_vista_movil_700_x_1200_10.png?v=1776892631&width=200',
        natacion:     'https://fubball.com/cdn/shop/files/para_el_banner_principal_Vista_de_escritorio_1920_x_700_vista_movil_700_x_1200_9.png?v=1776892411&width=200',
        voley:        'https://fubball.com/cdn/shop/files/para_el_banner_principal_Vista_de_escritorio_1920_x_700_vista_movil_700_x_1200_18.png?v=1776896489&width=200',
        futsal:       'https://fubball.com/cdn/shop/files/para_el_banner_principal_Vista_de_escritorio_1920_x_700_vista_movil_700_x_1200_12.png?v=1776893077&width=200',
        indumentaria: 'https://fubball.com/cdn/shop/files/TSI_3_4ebd6c7e-365e-4b7c-a994-f11ef1676eb4.png?v=1777496991&width=200'
    };
    function productImageUrl(name){
        const n = (name||'').toLowerCase();
        if(n.includes('futsal')) return CATEGORY_PHOTOS.futsal;
        if(n.includes('raqueta')||n.includes('grip')) return CATEGORY_PHOTOS.tenis;
        if(n.includes('boxeo')) return CATEGORY_PHOTOS.box;
        if(n.includes('silbato')||n.includes('net')||n.includes('rodillera')||n.includes('vóley')||n.includes('voley')) return CATEGORY_PHOTOS.voley;
        if(n.includes('natación')||n.includes('natacion')||n.includes('gorro')||n.includes('lente')) return CATEGORY_PHOTOS.natacion;
        if(n.includes('básquet')||n.includes('basquet')||n.includes('baloncesto')) return CATEGORY_PHOTOS.basquet;
        if(n.includes('casaca')||n.includes('camiseta')||n.includes('polo')||n.includes('chimpun')||n.includes('zapatilla')||n.includes('media')||n.includes('muñequera')||n.includes('munequera')) return CATEGORY_PHOTOS.indumentaria;
        return CATEGORY_PHOTOS.futbol;
    }
    // Si el item ya trae imagen_url real (columna pedido_items.imagen_url),
    // se usa directo; si no (todavía no hay fotos reales cargadas), cae al
    // mapeo por categoría de arriba.
    // La miniatura de tarjeta es un link que abre la imagen original en una
    // pestaña nueva (clic) y hace zoom con transform:scale al pasar el
    // cursor (inspección rápida sin clic) — pedido de almacén para comparar
    // contra el stock físico. Si no hay URL o la imagen falla al cargar, se
    // deja un placeholder visible en vez de un hueco vacío.
    function itemThumbHTML(item, idx, size){
        const src = item.imagenUrl || productImageUrl(item.product);
        const nombreEsc = escapeHtml(item.product);
        if(!src){
            return '<span class="item-thumb item-thumb--sinfoto" style="width:'+size+'px;height:'+size+'px" title="Sin imagen disponible">'+
                '<span class="item-thumb-placeholder-icon">📦</span>'+
            '</span>';
        }
        const url = encodeURI(src);
        return '<a class="item-thumb" href="'+url+'" target="_blank" rel="noopener" style="width:'+size+'px;height:'+size+'px" title="Ver imagen completa en pestaña nueva">'+
            '<img src="'+url+'" alt="'+nombreEsc+'" loading="lazy" onerror="itemThumbError(this)">'+
            '<span class="item-thumb-badge">🔍</span>'+
        '</a>';
    }
    function itemThumbError(imgEl){
        const wrap = imgEl.closest('.item-thumb');
        if(!wrap) return;
        wrap.classList.add('item-thumb--sinfoto');
        wrap.innerHTML = '<span class="item-thumb-placeholder-icon">📦</span>';
        wrap.removeAttribute('href');
        wrap.style.pointerEvents = 'none';
    }

    // Miniatura ampliada del modal de detalle: misma resolución de imagen,
    // pero con clic para lightbox en vez de pestaña nueva (el botón
    // "🔗 Ver imagen completa" de al lado cubre ese caso).
    function modalItemThumbHTML(item){
        const src = item.imagenUrl || productImageUrl(item.product);
        if(!src){
            return '<div class="modal-item-thumb modal-item-thumb--sinfoto" title="Sin imagen disponible"><span class="item-thumb-placeholder-icon">📦</span></div>';
        }
        const url = encodeURI(src);
        const nombreEsc = escapeHtml(item.product);
        return '<div class="modal-item-thumb">'+
            '<img src="'+url+'" alt="'+nombreEsc+'" data-full="'+url+'" data-nombre="'+nombreEsc+'" loading="lazy" onclick="abrirLightbox(this)" onerror="modalThumbError(this)">'+
        '</div>';
    }
    function modalItemLinkHTML(item){
        const src = item.imagenUrl || productImageUrl(item.product);
        if(!src) return '';
        return '<a class="modal-item-link" href="'+encodeURI(src)+'" target="_blank" rel="noopener">🔗 Ver imagen completa</a>';
    }
    function modalThumbError(imgEl){
        const wrap = imgEl.closest('.modal-item-thumb');
        if(!wrap) return;
        wrap.classList.add('modal-item-thumb--sinfoto');
        wrap.innerHTML = '<span class="item-thumb-placeholder-icon">📦</span>';
    }

    /* ---------- LIGHTBOX (zoom de imagen a pantalla completa) ---------- */
    function abrirLightbox(imgEl){
        const url = imgEl.dataset.full || imgEl.src;
        const nombre = imgEl.dataset.nombre || imgEl.alt || '';
        document.getElementById('lightboxImg').src = url;
        document.getElementById('lightboxImg').alt = nombre;
        document.getElementById('lightboxCaption').textContent = nombre;
        document.getElementById('lightboxOverlay').classList.add('open');
    }
    function cerrarLightbox(){
        document.getElementById('lightboxOverlay').classList.remove('open');
        document.getElementById('lightboxImg').src = '';
    }

    // Adapta una fila cruda de api/pedidos_listar.php / pedidos_detalle.php
    // (columnas de pedidos + canal + nombres de responsables + items) a la
    // forma que usan las funciones de render de abajo.
    function mapPedido(row){
        return {
            id: row.id,
            codigoOrden: row.codigo_orden,
            channel: row.canal_codigo,
            items: row.items.map(it => ({
                product: it.producto_nombre,
                variant: it.variante || '',
                sku: it.sku || '',
                qty: parseInt(it.cantidad, 10),
                imagenUrl: it.imagen_url || null
            })),
            customer: row.cliente_nombre,
            address: row.cliente_direccion,
            deadline: new Date(String(row.fecha_limite).replace(' ', 'T')),
            status: row.estado,
            flag: parseInt(row.requiere_verificar_pago, 10) === 1 ? 'pago' : null,
            metodoDespachoId: row.metodo_despacho_id !== null ? parseInt(row.metodo_despacho_id, 10) : null,
            etiquetaPdfUrl: row.etiqueta_pdf_url || null,
            responsables: {
                embalaje: row.responsable_embalaje_id ? {id: row.responsable_embalaje_id, nombre: row.resp_embalaje_nombre} : null,
                verificacion: row.responsable_verificacion_id ? {id: row.responsable_verificacion_id, nombre: row.resp_verificacion_nombre} : null,
                despacho: row.responsable_despacho_id ? {id: row.responsable_despacho_id, nombre: row.resp_despacho_nombre} : null
            }
        };
    }

    /* ---------- RENDER: KDS ---------- */
    function renderFilters(){
        const box = document.getElementById('channelFilters');
        const channels = ['ALL'].concat(Object.keys(channelMeta));
        box.innerHTML = channels.map(c=>{
            const label = c==='ALL' ? 'Todos' : channelMeta[c].label;
            const active = c===currentChannelFilter ? 'active' : '';
            return '<button type="button" class="chip '+active+'" onclick="setChannelFilter(\''+c+'\')">'+escapeHtml(label)+'</button>';
        }).join('');
    }
    function setChannelFilter(c){
        currentChannelFilter = c;
        renderFilters();
        cargarPedidos();
    }

    function itemsSectionHTML(o){
        if(o.items.length===1){
            const it = o.items[0];
            const detalle = [it.variant, it.sku ? ('SKU '+it.sku) : ''].filter(Boolean).map(escapeHtml).join(' · ');
            return '<div class="ticket-product-row">'+
                itemThumbHTML(it,0,84)+
                '<div class="ticket-product-info">'+
                    '<div class="ticket-product">'+escapeHtml(it.product)+'</div>'+
                    (detalle ? '<div class="ticket-variant">'+detalle+'</div>' : '')+
                    '<button type="button" class="detail-link" onclick="abrirDetalle('+o.id+')">📋 Ver detalle para embalar</button>'+
                '</div>'+
            '</div>';
        }
        const thumbs = o.items.slice(0,3).map((it,i)=>itemThumbHTML(it,i,44)).join('');
        const extra = o.items.length>3 ? '<span class="item-thumb more" style="width:44px;height:44px;">+'+(o.items.length-3)+'</span>' : '';
        return '<div class="ticket-product-row">'+
            '<div class="item-thumbs-stack">'+thumbs+extra+'</div>'+
            '<div class="ticket-product-info">'+
                '<div class="ticket-product">'+o.items.length+' productos</div>'+
                '<button type="button" class="detail-link" onclick="abrirDetalle('+o.id+')">📋 Ver detalle para embalar</button>'+
            '</div>'+
        '</div>';
    }
    function responsablesResumenHTML(o){
        const items = [];
        if(o.responsables.embalaje) items.push('📦 '+escapeHtml(o.responsables.embalaje.nombre));
        if(o.responsables.verificacion) items.push('🔍 '+escapeHtml(o.responsables.verificacion.nombre));
        if(o.responsables.despacho) items.push('🚚 '+escapeHtml(o.responsables.despacho.nombre));
        if(!items.length) return '';
        return '<div class="resp-trail">'+items.join(' · ')+'</div>';
    }
    function employeeSelectHTML(o, faseKey){
        const seleccionadoId = o.responsables[faseKey] ? String(o.responsables[faseKey].id) : '';
        const opts = usuariosCache.map(u=>
            '<option value="'+u.id+'"'+(String(u.id)===seleccionadoId ? ' selected' : '')+'>'+escapeHtml(u.nombre)+'</option>'
        ).join('');
        return '<select class="resp-select" id="sel-'+o.id+'"><option value="">— Selecciona —</option>'+opts+'</select>';
    }
    // Solo se pinta cuando el pedido llegó por integración automática
    // (Shopify, extensión Chrome) y todavía no tiene método de despacho —
    // ver PedidoRepository::avanzarFase(). Altas manuales ya lo traen
    // desde pedidos_nuevo.php, así que no se les vuelve a pedir.
    function metodoSelectHTML(o){
        const opts = metodosCache.map(m=>'<option value="'+m.id+'">'+escapeHtml(m.nombre)+'</option>').join('');
        return '<select class="resp-select" id="metodo-'+o.id+'"><option value="">— Selecciona —</option>'+opts+'</select>';
    }

    function ticketCardHTML(o){
        const sla = slaInfo(o);
        const meta = channelMeta[o.channel] || {label:o.channel, color:'#666'};
        const fase = FASES[o.status];
        const codigoOrdenEsc = escapeHtml(o.codigoOrden);
        const enlaceOrden = o.etiquetaPdfUrl
            ? '<a class="ticket-id" href="'+o.etiquetaPdfUrl+'" target="_blank" rel="noopener" title="Ver / descargar etiqueta PDF">#'+codigoOrdenEsc+' 🔗</a>'
            : '<span class="ticket-id">#'+codigoOrdenEsc+'</span>';
        const necesitaMetodo = o.status === 'verificacion' && o.metodoDespachoId === null;
        const botonEtiqueta = o.etiquetaPdfUrl
            ? '<button class="btn btn-outline" onclick="imprimirEtiqueta('+o.id+')">🖨️ Etiqueta</button>'
            : '<button class="btn btn-outline" onclick="elegirArchivoEtiqueta('+o.id+')">📎 Subir etiqueta PDF</button>';
        // El botón de eliminar solo se pinta para admin (ES_ADMIN, inyectado
        // desde PHP) — el servidor vuelve a validar el rol en
        // api/pedidos_eliminar.php de todas formas.
        const botonEliminar = ES_ADMIN
            ? '<button class="btn-delete-icon" onclick="eliminarPedido('+o.id+')" title="Eliminar pedido">🗑️ Eliminar</button>'
            : '';
        return ''+
        '<div class="ticket">'+
            '<div class="ticket-sla '+sla.level+'"></div>'+
            '<div class="ticket-body">'+
                '<div class="ticket-top">'+
                    '<span class="channel-badge" style="background:'+meta.color+'">'+escapeHtml(meta.label)+'</span>'+
                    '<div class="ticket-top-right">'+enlaceOrden+botonEliminar+'</div>'+
                '</div>'+
                itemsSectionHTML(o)+
                (o.flag==='pago' ? '<div class="ticket-flag">⚠️ Verificar pago antes de despachar</div>' : '')+
                responsablesResumenHTML(o)+
                '<div class="ticket-deadline '+sla.level+'">⏰ '+sla.text+'</div>'+
                (necesitaMetodo ? '<div class="resp-select-row"><label class="resp-select-label">Método de despacho (pendiente de confirmar)</label>'+metodoSelectHTML(o)+'</div>' : '')+
                (fase ? (
                    '<div class="resp-select-row"><label class="resp-select-label">'+fase.selectLabel+'</label>'+employeeSelectHTML(o, fase.key)+'</div>'+
                    '<div class="ticket-actions">'+
                        botonEtiqueta+
                        '<button class="btn btn-primary" onclick="avanzarConResponsable('+o.id+',\''+fase.next+'\',\''+fase.key+'\')">'+fase.label+'</button>'+
                    '</div>'+
                    '<input type="file" accept="application/pdf" id="archivo-'+o.id+'" style="display:none" onchange="subirEtiqueta('+o.id+')">'
                ) : '')+
            '</div>'+
        '</div>';
    }

    function renderKDS(){
        const filtered = pedidosCache.filter(o => currentChannelFilter==='ALL' || o.channel===currentChannelFilter);
        const nuevos = filtered.filter(o=>o.status==='nuevo');
        const embalando = filtered.filter(o=>o.status==='embalando');
        const verificacion = filtered.filter(o=>o.status==='verificacion');

        document.getElementById('countNuevos').textContent = nuevos.length;
        document.getElementById('countEmbalando').textContent = embalando.length;
        document.getElementById('countVerificacion').textContent = verificacion.length;
        document.getElementById('colNuevos').innerHTML = nuevos.length ? nuevos.map(ticketCardHTML).join('') : '<div class="empty">Sin pedidos nuevos en este canal.</div>';
        document.getElementById('colEmbalando').innerHTML = embalando.length ? embalando.map(ticketCardHTML).join('') : '<div class="empty">Nada en embalaje.</div>';
        document.getElementById('colVerificacion').innerHTML = verificacion.length ? verificacion.map(ticketCardHTML).join('') : '<div class="empty">Nada en verificación.</div>';
    }

    /* ---------- MODAL DE DETALLE ---------- */
    async function abrirDetalle(id){
        try {
            const resp = await fetch(API_BASE+'pedidos_detalle.php?id='+id);
            const data = await resp.json();
            if(!data.ok){ toast('⚠️ '+(data.error||'No se pudo cargar el detalle.')); return; }

            const o = mapPedido(data.pedido);
            document.getElementById('detailModalTitle').textContent = 'Detalle del pedido #'+o.codigoOrden;
            document.getElementById('detailModalBody').innerHTML = o.items.map((it,i)=>{
                const detalle = [it.variant, it.sku ? ('SKU '+it.sku) : ''].filter(Boolean).map(escapeHtml).join(' · ');
                return '<div class="modal-item-row">'+
                    modalItemThumbHTML(it)+
                    '<div class="modal-item-col">'+
                        '<div class="modal-item-top">'+
                            '<div class="modal-item-name">'+escapeHtml(it.product)+'</div>'+
                            '<div class="modal-item-qty">x'+it.qty+'</div>'+
                        '</div>'+
                        (detalle ? '<div class="modal-item-meta">'+detalle+'</div>' : '')+
                        modalItemLinkHTML(it)+
                    '</div>'+
                '</div>';
            }).join('');
            document.getElementById('detailModalOverlay').classList.add('open');
        } catch(e) {
            toast('⚠️ Error de red al abrir el detalle.');
        }
    }
    function cerrarDetalle(){
        document.getElementById('detailModalOverlay').classList.remove('open');
    }

    /* ---------- ETIQUETA (impresión) ---------- */
    function playPrintSound(){
        try{
            const ctx = new (window.AudioContext||window.webkitAudioContext)();
            [0, 0.12].forEach((delay, i) => {
                const o = ctx.createOscillator(); const g = ctx.createGain();
                o.type = 'square'; o.frequency.value = i===0 ? 660 : 990;
                o.connect(g); g.connect(ctx.destination);
                g.gain.setValueAtTime(0.0001, ctx.currentTime+delay);
                g.gain.exponentialRampToValueAtTime(0.15, ctx.currentTime+delay+0.01);
                g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime+delay+0.12);
                o.start(ctx.currentTime+delay); o.stop(ctx.currentTime+delay+0.14);
            });
        }catch(e){}
    }
    function imprimirEtiqueta(id){
        const o = pedidosCache.find(x=>x.id===id);
        if(!o || !o.etiquetaPdfUrl) return;
        const url = o.etiquetaPdfUrl;

        playPrintSound();
        toast('🖨️ Abriendo etiqueta PDF #'+o.codigoOrden+'…');

        // Intenta imprimir directo el PDF real vía un iframe oculto (funciona
        // si el archivo ya está subido a /fubball/etiquetas/ y el servidor
        // permite que se cargue dentro de un iframe). Si falla o no existe,
        // cae a abrir el PDF en una pestaña nueva.
        const iframe = document.createElement('iframe');
        iframe.style.position = 'fixed';
        iframe.style.right = '0'; iframe.style.bottom = '0';
        iframe.style.width = '0'; iframe.style.height = '0'; iframe.style.border = '0';
        iframe.src = url;
        document.body.appendChild(iframe);

        let manejado = false;
        const abrirFallback = () => {
            if(manejado) return;
            manejado = true;
            window.open(url, '_blank');
            setTimeout(()=>iframe.remove(), 500);
        };

        iframe.onload = () => {
            if(manejado) return;
            manejado = true;
            try{
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
                setTimeout(()=>iframe.remove(), 30000);
            }catch(e){
                abrirFallback();
            }
        };
        iframe.onerror = abrirFallback;
        setTimeout(abrirFallback, 4000);
    }

    /* ---------- ETIQUETA (subida) ---------- */
    // El botón visible dispara el <input type="file"> oculto de esa
    // tarjeta (ver ticketCardHTML); al elegir el archivo, subirEtiqueta()
    // hace el POST.
    function elegirArchivoEtiqueta(id){
        const input = document.getElementById('archivo-'+id);
        if(input) input.click();
    }
    async function subirEtiqueta(id){
        const input = document.getElementById('archivo-'+id);
        const archivo = input && input.files[0];
        if(!archivo) return;

        const formData = new FormData();
        formData.append('pedido_id', id);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('archivo', archivo);

        try {
            const resp = await fetch(API_BASE+'pedido_subir_etiqueta.php', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();
            if(!data.ok){
                toast('⚠️ '+(data.error||'No se pudo subir la etiqueta.'));
                input.value = '';
                return;
            }
            toast('📎 Etiqueta subida correctamente');
            cargarPedidos();
        } catch(e) {
            toast('⚠️ Error de red al subir la etiqueta.');
        }
    }

    /* ---------- CARGA DE DATOS ---------- */
    async function cargarPedidos(){
        try {
            const resp = await fetch(API_BASE+'pedidos_listar.php?estados=nuevo,embalando,verificacion');
            const data = await resp.json();
            if(!data.ok){ toast('⚠️ '+(data.error||'No se pudieron cargar los pedidos.')); return; }
            pedidosCache = data.pedidos.map(mapPedido);
            renderKDS();
        } catch(e) {
            toast('⚠️ Error de red al cargar los pedidos.');
        }
    }
    // Se pide una sola vez por carga de página (no por cada tarjeta).
    async function cargarUsuarios(){
        try {
            const resp = await fetch(API_BASE+'usuarios_listar.php?activos=1');
            const data = await resp.json();
            if(data.ok) usuariosCache = data.usuarios;
        } catch(e) {
            // Silencioso: el select de responsable quedará vacío, pero el
            // tablero sigue siendo usable (ver pedidos, imprimir etiqueta).
        }
    }
    async function cargarMetodos(){
        try {
            const resp = await fetch(API_BASE+'metodos_listar.php');
            const data = await resp.json();
            if(data.ok) metodosCache = data.metodos;
        } catch(e) {
            // Silencioso: si un pedido necesita el select de método y esto
            // falló, avanzarConResponsable() lo va a rechazar con un toast
            // claro en vez de romperse en silencio.
        }
    }

    /* ---------- ACCIONES ---------- */
    async function avanzarConResponsable(id, nuevoEstado, faseKey){
        const select = document.getElementById('sel-'+id);
        const responsableId = select ? select.value : '';
        if(!responsableId){
            toast('⚠️ Selecciona un responsable antes de continuar');
            return;
        }

        // El select de método solo existe en la tarjeta si el pedido llegó
        // sin método (Shopify/extensión Chrome) — mismo criterio que el
        // backend en PedidoRepository::avanzarFase().
        const selectMetodo = document.getElementById('metodo-'+id);
        let metodoDespachoId = null;
        if(selectMetodo){
            if(!selectMetodo.value){
                toast('⚠️ Selecciona el método de despacho antes de continuar');
                return;
            }
            metodoDespachoId = selectMetodo.value;
        }

        try {
            const resp = await fetch(API_BASE+'pedidos_avanzar.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    pedido_id: id,
                    nuevo_estado: nuevoEstado,
                    campo_responsable: faseKey,
                    responsable_id: responsableId,
                    metodo_despacho_id: metodoDespachoId,
                    csrf_token: CSRF_TOKEN
                })
            });
            const data = await resp.json();
            if(!data.ok){
                toast('⚠️ '+(data.error||'No se pudo avanzar el pedido.'));
                return;
            }
            toast('✅ Pedido actualizado');
            cargarPedidos();
        } catch(e) {
            toast('⚠️ Error de red al avanzar el pedido.');
        }
    }

    // Busca el pedido en pedidosCache (no en el atributo onclick) para
    // mostrar el código de orden en el confirm() sin tener que
    // interpolarlo crudo dentro de un atributo HTML — mismo patrón que
    // imprimirEtiqueta(id).
    async function eliminarPedido(id){
        const o = pedidosCache.find(x=>x.id===id);
        const codigo = o ? o.codigoOrden : ('#'+id);
        if(!confirm('¿Eliminar el pedido '+codigo+'? Esta acción no se puede deshacer.')) return;

        try {
            const resp = await fetch(API_BASE+'pedidos_eliminar.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ pedido_id: id, csrf_token: CSRF_TOKEN })
            });
            const data = await resp.json();
            if(!data.ok){
                toast('⚠️ '+(data.error||'No se pudo eliminar el pedido.'));
                return;
            }
            toast('🗑️ Pedido eliminado');
            cargarPedidos();
        } catch(e) {
            toast('⚠️ Error de red al eliminar el pedido.');
        }
    }

    /* ---------- INIT ---------- */
    async function init(){
        renderFilters();
        await Promise.all([cargarUsuarios(), cargarMetodos()]);
        await cargarPedidos();
    }
    init();

    // Refresco automático: nuevos pedidos entrando y el semáforo SLA
    // avanzando con la hora real, sin depender de que alguien recargue.
    setInterval(cargarPedidos, 30000);
    </script>
<?php
require __DIR__ . '/core/ui/layout_footer.php';
