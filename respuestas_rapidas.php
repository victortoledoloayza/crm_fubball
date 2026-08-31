<?php
/**
 * respuestas_rapidas.php
 *
 * Gestión (listar/crear/editar/borrar) de las Respuestas Rápidas que
 * consume la extensión de Chrome vía api/webhooks/respuestas_rapidas.php.
 * A diferencia de usuarios.php (formularios de página completa), esta
 * vista carga y guarda todo por fetch() a los api/respuestas_rapidas_*.php
 * de sesión — necesario porque los adjuntos (0, 1 o varios por respuesta,
 * ver respuestas_rapidas_adjuntos) se agregan/quitan sin recargar la
 * página, igual que la subida de etiqueta en pedidos.php.
 */

require_once __DIR__ . '/core/bootstrap.php';

Auth::requireLogin();

$pdo = Database::getConnection();
$canales = $pdo->query('SELECT id, codigo, nombre FROM canales WHERE activo = 1 ORDER BY id')->fetchAll();

$tituloPagina = 'Respuestas Rápidas';
$navActiva    = 'respuestas';
require __DIR__ . '/core/ui/layout_header.php';
?>
    <style>
        .cabecera-pagina { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .cabecera-pagina h1 { margin: 0; font-size: 22px; }
        .boton-primario {
            background: #d6483d; color: #fff; border: none; padding: 10px 18px;
            border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none;
        }
        .boton-primario:hover { background: #b83a30; }

        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        th, td { text-align: left; padding: 12px 14px; font-size: 13px; border-bottom: 1px solid #eef0f3; vertical-align: top; }
        th { background: #fafafa; color: #6b7280; font-weight: 600; text-transform: uppercase; font-size: 11px; letter-spacing: .03em; }
        td.texto-celda { max-width: 340px; color: #4b5563; white-space: pre-wrap; }
        .badge { padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; display: inline-block; }
        .badge--activo { background: #eafaf0; color: #166534; }
        .badge--inactivo { background: #f3f4f6; color: #6b7280; }
        .badge--canal { background: #eef2ff; color: #3730a3; }
        .adjunto-chip {
            display: inline-flex; align-items: center; gap: 4px; background: #f3f4f6; color: #374151;
            border-radius: 999px; padding: 3px 10px; font-size: 11px; margin: 2px 4px 2px 0; text-decoration: none;
        }
        .adjunto-chip:hover { background: #e5e7eb; }
        .acciones a, .acciones button {
            font-size: 12px; font-weight: 600; text-decoration: none; border: none; background: none; cursor: pointer; padding: 0; margin-right: 12px;
        }
        .acciones a, .acciones button.editar { color: #2563eb; }
        .acciones button.borrar { color: #a3231a; }
        .vacio { text-align: center; color: #9ca3af; padding: 30px 0; }

        .modal-fondo { display: none; position: fixed; inset: 0; background: rgba(22,28,43,0.55); align-items: center; justify-content: center; z-index: 50; padding: 20px; }
        .modal-fondo.abierto { display: flex; }
        .modal-caja { background: #fff; width: 100%; max-width: 520px; max-height: 88vh; overflow-y: auto; border-radius: 12px; padding: 28px; }
        .modal-caja h2 { margin: 0 0 18px; font-size: 18px; }
        .modal-caja label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .modal-caja input, .modal-caja select, .modal-caja textarea {
            width: 100%; padding: 9px 10px; margin-bottom: 14px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit; box-sizing: border-box;
        }
        .modal-caja textarea { resize: vertical; min-height: 80px; }
        .fila-doble { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .fila-checkbox { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
        .fila-checkbox input { width: auto; margin: 0; }
        .lista-adjuntos-actuales { margin-bottom: 10px; }
        .adjunto-actual-fila {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            background: #f9fafb; border: 1px solid #eef0f3; border-radius: 6px; padding: 6px 10px; margin-bottom: 6px; font-size: 12px;
        }
        .adjunto-actual-fila a { color: #374151; text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .adjunto-actual-fila button { color: #a3231a; background: none; border: none; cursor: pointer; font-size: 12px; font-weight: 600; }
        .modal-botones { display: flex; gap: 10px; justify-content: flex-end; margin-top: 6px; }
        .boton-secundario { background: #f3f4f6; color: #374151; border: none; padding: 10px 16px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }

        #toast { position: fixed; bottom: 20px; right: 20px; z-index: 100; display: flex; flex-direction: column; gap: 8px; }
        .toast-item { background: #1f2430; color: #fff; padding: 10px 16px; border-radius: 8px; font-size: 13px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
    </style>

    <div class="cabecera-pagina">
        <h1>Respuestas Rápidas</h1>
        <button type="button" class="boton-primario" onclick="abrirModalNuevo()">+ Nueva respuesta</button>
    </div>

    <table>
        <thead>
            <tr>
                <th>Orden</th>
                <th>Título</th>
                <th>Texto</th>
                <th>Canal</th>
                <th>Adjuntos</th>
                <th>Estado</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="cuerpoTabla">
            <tr><td colspan="7" class="vacio">Cargando…</td></tr>
        </tbody>
    </table>

    <div class="modal-fondo" id="modalRespuesta">
        <div class="modal-caja">
            <h2 id="modalTitulo">Nueva respuesta</h2>
            <form id="formRespuesta" onsubmit="return guardarRespuesta(event)">
                <input type="hidden" id="campoId" name="id" value="">

                <label for="campoTitulo">Título</label>
                <input type="text" id="campoTitulo" name="titulo" required maxlength="150">

                <label for="campoTexto">Texto</label>
                <textarea id="campoTexto" name="texto" required></textarea>

                <div class="fila-doble">
                    <div>
                        <label for="campoCanal">Canal</label>
                        <select id="campoCanal" name="canal_id">
                            <option value="">Todos los canales</option>
                            <?php foreach ($canales as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="campoOrden">Orden</label>
                        <input type="number" id="campoOrden" name="orden" min="0" value="0">
                    </div>
                </div>

                <div class="fila-checkbox">
                    <input type="checkbox" id="campoActivo" name="activo" checked>
                    <label for="campoActivo" style="margin:0;">Activa (visible para la extensión)</label>
                </div>

                <div id="bloqueAdjuntosActuales" style="display:none;">
                    <label>Adjuntos actuales</label>
                    <div class="lista-adjuntos-actuales" id="listaAdjuntosActuales"></div>
                </div>

                <label for="campoAdjuntos">Agregar adjuntos (imagen, video, pdf, audio o documento — hasta 25MB c/u)</label>
                <input type="file" id="campoAdjuntos" name="adjuntos[]" multiple>

                <div class="modal-botones">
                    <button type="button" class="boton-secundario" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="boton-primario" id="botonGuardar">Crear respuesta</button>
                </div>
            </form>
        </div>
    </div>

    <div id="toast"></div>

    <script>
        const API_BASE = <?= json_encode(baseUrl('api/'), JSON_UNESCAPED_SLASHES) ?>;
        const CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;

        let respuestasCache = [];

        function toast(msg){
            const box = document.getElementById('toast');
            const el = document.createElement('div');
            el.className = 'toast-item';
            el.textContent = msg;
            box.appendChild(el);
            setTimeout(()=>{ el.style.opacity='0'; el.style.transition='opacity .3s'; setTimeout(()=>el.remove(),300); }, 3200);
        }

        function escaparHtml(texto){
            const div = document.createElement('div');
            div.textContent = texto ?? '';
            return div.innerHTML;
        }

        function iconoTipo(tipo){
            return { imagen: '🖼️', video: '🎬', pdf: '📄', audio: '🎵', documento: '📎' }[tipo] || '📎';
        }

        async function cargarRespuestas(){
            try {
                const resp = await fetch(API_BASE+'respuestas_rapidas_listar.php');
                const data = await resp.json();
                if(!data.ok){ toast('⚠️ '+(data.error||'No se pudieron cargar las respuestas.')); return; }
                respuestasCache = data.respuestas;
                renderTabla();
            } catch(e) {
                toast('⚠️ Error de red al cargar las respuestas.');
            }
        }

        function renderTabla(){
            const cuerpo = document.getElementById('cuerpoTabla');
            if(respuestasCache.length === 0){
                cuerpo.innerHTML = '<tr><td colspan="7" class="vacio">Todavía no hay respuestas rápidas. Crea la primera.</td></tr>';
                return;
            }

            cuerpo.innerHTML = respuestasCache.map(r => {
                const canalHtml = r.canal_nombre
                    ? '<span class="badge badge--canal">'+escaparHtml(r.canal_nombre)+'</span>'
                    : '<span style="color:#9ca3af;">Todos</span>';

                const adjuntosHtml = r.adjuntos.length
                    ? r.adjuntos.map(a => '<a class="adjunto-chip" href="'+a.url+'" target="_blank" rel="noopener">'+iconoTipo(a.tipo)+' '+escaparHtml(a.nombre_archivo)+'</a>').join('')
                    : '<span style="color:#9ca3af;">—</span>';

                const estadoHtml = Number(r.activo) === 1
                    ? '<span class="badge badge--activo">Activa</span>'
                    : '<span class="badge badge--inactivo">Inactiva</span>';

                return '<tr>'
                    + '<td>'+r.orden+'</td>'
                    + '<td>'+escaparHtml(r.titulo)+'</td>'
                    + '<td class="texto-celda">'+escaparHtml(r.texto)+'</td>'
                    + '<td>'+canalHtml+'</td>'
                    + '<td>'+adjuntosHtml+'</td>'
                    + '<td>'+estadoHtml+'</td>'
                    + '<td class="acciones">'
                        + '<button type="button" class="editar" onclick="abrirModalEditar('+r.id+')">Editar</button>'
                        + '<button type="button" class="borrar" onclick="eliminarRespuesta('+r.id+')">Borrar</button>'
                    + '</td>'
                    + '</tr>';
            }).join('');
        }

        function abrirModalNuevo(){
            document.getElementById('formRespuesta').reset();
            document.getElementById('campoId').value = '';
            document.getElementById('campoActivo').checked = true;
            document.getElementById('modalTitulo').textContent = 'Nueva respuesta';
            document.getElementById('botonGuardar').textContent = 'Crear respuesta';
            document.getElementById('bloqueAdjuntosActuales').style.display = 'none';
            document.getElementById('listaAdjuntosActuales').innerHTML = '';
            document.getElementById('modalRespuesta').classList.add('abierto');
        }

        function abrirModalEditar(id){
            const r = respuestasCache.find(x => x.id === id);
            if(!r) return;

            document.getElementById('formRespuesta').reset();
            document.getElementById('campoId').value = r.id;
            document.getElementById('campoTitulo').value = r.titulo;
            document.getElementById('campoTexto').value = r.texto;
            document.getElementById('campoCanal').value = r.canal_id ?? '';
            document.getElementById('campoOrden').value = r.orden;
            document.getElementById('campoActivo').checked = Number(r.activo) === 1;
            document.getElementById('modalTitulo').textContent = 'Editar respuesta';
            document.getElementById('botonGuardar').textContent = 'Guardar cambios';

            const bloque = document.getElementById('bloqueAdjuntosActuales');
            const lista = document.getElementById('listaAdjuntosActuales');
            if(r.adjuntos.length){
                bloque.style.display = 'block';
                lista.innerHTML = r.adjuntos.map(a =>
                    '<div class="adjunto-actual-fila">'
                    + '<a href="'+a.url+'" target="_blank" rel="noopener">'+iconoTipo(a.tipo)+' '+escaparHtml(a.nombre_archivo)+'</a>'
                    + '<button type="button" onclick="eliminarAdjunto('+a.id+', '+r.id+')">Quitar</button>'
                    + '</div>'
                ).join('');
            } else {
                bloque.style.display = 'none';
                lista.innerHTML = '';
            }

            document.getElementById('modalRespuesta').classList.add('abierto');
        }

        function cerrarModal(){
            document.getElementById('modalRespuesta').classList.remove('abierto');
        }

        async function guardarRespuesta(evento){
            evento.preventDefault();

            const formData = new FormData(document.getElementById('formRespuesta'));
            formData.append('csrf_token', CSRF_TOKEN);
            if(!document.getElementById('campoActivo').checked){
                formData.delete('activo');
            }

            try {
                const resp = await fetch(API_BASE+'respuestas_rapidas_guardar.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await resp.json();
                if(!data.ok){
                    toast('⚠️ '+(data.error||'No se pudo guardar la respuesta.'));
                    return false;
                }
                toast('✅ Respuesta guardada correctamente');
                cerrarModal();
                cargarRespuestas();
            } catch(e) {
                toast('⚠️ Error de red al guardar la respuesta.');
            }
            return false;
        }

        async function eliminarRespuesta(id){
            if(!confirm('¿Borrar esta respuesta y todos sus adjuntos? Esta acción no se puede deshacer.')) return;

            try {
                const resp = await fetch(API_BASE+'respuestas_rapidas_eliminar.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ id: id, csrf_token: CSRF_TOKEN })
                });
                const data = await resp.json();
                if(!data.ok){
                    toast('⚠️ '+(data.error||'No se pudo borrar la respuesta.'));
                    return;
                }
                toast('🗑️ Respuesta borrada');
                cargarRespuestas();
            } catch(e) {
                toast('⚠️ Error de red al borrar la respuesta.');
            }
        }

        async function eliminarAdjunto(adjuntoId, respuestaId){
            if(!confirm('¿Quitar este adjunto?')) return;

            try {
                const resp = await fetch(API_BASE+'respuestas_rapidas_adjunto_eliminar.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ adjunto_id: adjuntoId, csrf_token: CSRF_TOKEN })
                });
                const data = await resp.json();
                if(!data.ok){
                    toast('⚠️ '+(data.error||'No se pudo quitar el adjunto.'));
                    return;
                }
                toast('🗑️ Adjunto quitado');
                await cargarRespuestas();
                abrirModalEditar(respuestaId);
            } catch(e) {
                toast('⚠️ Error de red al quitar el adjunto.');
            }
        }

        cargarRespuestas();
    </script>
<?php
require __DIR__ . '/core/ui/layout_footer.php';
