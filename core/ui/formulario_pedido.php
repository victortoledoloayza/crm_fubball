<?php
/**
 * core/ui/formulario_pedido.php
 *
 * Formulario de alta/edición de pedidos — compartido por pedidos_nuevo.php
 * (abierto a cualquier usuario logueado) y pedidos_editar.php (admin-only,
 * el gate de rol vive en ese archivo, no acá). Una sola copia de la lógica
 * de filas de producto (agregarFilaProducto) y de la carga desde PDF de
 * TSI, para que ambos modos nunca se desincronicen entre sí.
 *
 * Variables esperadas, seteadas por quien incluya este archivo:
 *   $canales, $metodosDespacho : arrays ya cargados de la BD
 *   $error                     : string, mensaje de error a mostrar (o '')
 *   $modoEdicion               : bool
 *   $pedidoEnEdicion           : array con los datos actuales + 'items'
 *                                (null cuando $modoEdicion es false)
 *   $accionFormulario          : URL a la que postea el <form>
 *   $tituloFormulario          : string
 *   $subtituloFormulario       : string
 *   $textoBotonSubmit          : string
 */
?>
    <style>
        .form-card { background: #fff; border: 1px solid #e5e7ee; border-radius: 12px; padding: 24px; max-width: 720px; }
        .form-card h1 { margin: 0 0 4px; font-size: 20px; }
        .form-card p.subtitulo { color: #6b7280; font-size: 13px; margin: 0 0 22px; }
        .campo-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 4px; }
        @media (max-width: 620px) { .campo-grid { grid-template-columns: 1fr; } }
        label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        input[type="text"], input[type="email"], input[type="number"], input[type="datetime-local"], select {
            width: 100%; padding: 9px 10px; margin-bottom: 16px; border: 1px solid #d1d5db; border-radius: 6px;
            font-size: 14px; font-family: inherit; box-sizing: border-box;
        }
        .checkbox-row { display: flex; align-items: center; gap: 8px; margin-bottom: 18px; }
        .checkbox-row input { width: auto; margin: 0; }
        .checkbox-row label { margin: 0; }

        .items-titulo { font-weight: 700; font-size: 14px; margin: 22px 0 10px; }
        .item-row { display: grid; grid-template-columns: 2fr 1.4fr 1fr 0.7fr 0.9fr auto; gap: 8px; align-items: start; margin-bottom: 8px; }
        @media (max-width: 760px) { .item-row { grid-template-columns: 1fr; } }
        .item-row input { margin-bottom: 0; }
        .item-row .quitar-item { background: #fdecec; color: #a3231a; border: none; border-radius: 6px; padding: 9px 10px; font-size: 12px; font-weight: 700; cursor: pointer; }

        .btn-agregar { background: #f3f4f6; color: #374151; border: none; padding: 9px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; margin-bottom: 22px; }
        .btn-primario { background: #d6483d; color: #fff; border: none; padding: 11px 22px; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; }
        .btn-primario:hover { background: #b83a30; }
        .mensaje-error { background: #fdecec; border: 1px solid #f6b8b3; color: #a3231a; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 18px; max-width: 720px; }

        .tsi-upload-box { background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 8px; padding: 14px 16px; margin-bottom: 18px; }
        .tsi-upload-box label { margin-bottom: 8px; }
        .tsi-upload-msg { font-size: 12.5px; margin-top: 8px; font-weight: 600; }
    </style>

    <?php if ($error !== ''): ?>
        <div class="mensaje-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="form-card">
        <h1><?= htmlspecialchars($tituloFormulario, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="subtitulo"><?= htmlspecialchars($subtituloFormulario, ENT_QUOTES, 'UTF-8') ?></p>

        <form method="post" action="<?= htmlspecialchars($accionFormulario, ENT_QUOTES, 'UTF-8') ?>" id="formPedido">
            <?= csrfCampo() ?>
            <?php if ($modoEdicion): ?>
                <input type="hidden" name="pedido_id" value="<?= (int) $pedidoEnEdicion['id'] ?>">
            <?php endif; ?>

            <div class="campo-grid">
                <div>
                    <label for="canal_id">Canal</label>
                    <select id="canal_id" name="canal_id" required>
                        <option value="">— Selecciona —</option>
                        <?php foreach ($canales as $c): ?>
                            <option
                                value="<?= (int) $c['id'] ?>"
                                data-codigo="<?= htmlspecialchars($c['codigo'], ENT_QUOTES, 'UTF-8') ?>"
                                <?= $modoEdicion && (int) $pedidoEnEdicion['canal_id'] === (int) $c['id'] ? 'selected' : '' ?>
                            ><?= htmlspecialchars($c['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="fecha_limite">Fecha límite de despacho</label>
                    <input type="datetime-local" id="fecha_limite" name="fecha_limite" required
                        value="<?= $modoEdicion ? htmlspecialchars(str_replace(' ', 'T', substr($pedidoEnEdicion['fecha_limite'], 0, 16)), ENT_QUOTES, 'UTF-8') : '' ?>">
                </div>
            </div>

            <div id="tsiUploadBox" class="tsi-upload-box" style="display:none;">
                <label for="tsiPdfInput">📄 Cargar desde PDF de Orden de Pedido</label>
                <input type="file" id="tsiPdfInput" accept="application/pdf">
                <div id="tsiUploadMsg" class="tsi-upload-msg"></div>
            </div>

            <div class="campo-grid">
                <div>
                    <label for="cliente_nombre">Nombre del cliente</label>
                    <input type="text" id="cliente_nombre" name="cliente_nombre" required
                        value="<?= $modoEdicion ? htmlspecialchars($pedidoEnEdicion['cliente_nombre'], ENT_QUOTES, 'UTF-8') : '' ?>">
                </div>
                <div>
                    <label for="cliente_dni">DNI</label>
                    <input type="text" id="cliente_dni" name="cliente_dni"
                        value="<?= $modoEdicion ? htmlspecialchars((string) $pedidoEnEdicion['cliente_dni'], ENT_QUOTES, 'UTF-8') : '' ?>">
                </div>
            </div>

            <div class="campo-grid">
                <div>
                    <label for="cliente_telefono">Teléfono</label>
                    <input type="text" id="cliente_telefono" name="cliente_telefono"
                        value="<?= $modoEdicion ? htmlspecialchars((string) $pedidoEnEdicion['cliente_telefono'], ENT_QUOTES, 'UTF-8') : '' ?>">
                </div>
                <div>
                    <label for="cliente_email">Email (opcional)</label>
                    <input type="email" id="cliente_email" name="cliente_email"
                        value="<?= $modoEdicion ? htmlspecialchars((string) $pedidoEnEdicion['cliente_email'], ENT_QUOTES, 'UTF-8') : '' ?>">
                </div>
            </div>

            <label for="cliente_direccion">Dirección</label>
            <input type="text" id="cliente_direccion" name="cliente_direccion"
                value="<?= $modoEdicion ? htmlspecialchars((string) $pedidoEnEdicion['cliente_direccion'], ENT_QUOTES, 'UTF-8') : '' ?>">

            <div class="campo-grid">
                <div>
                    <label for="metodo_despacho_id">Método de despacho</label>
                    <select id="metodo_despacho_id" name="metodo_despacho_id">
                        <option value="">— Selecciona —</option>
                        <?php foreach ($metodosDespacho as $m): ?>
                            <option
                                value="<?= (int) $m['id'] ?>"
                                <?= $modoEdicion && (int) ($pedidoEnEdicion['metodo_despacho_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>
                            ><?= htmlspecialchars($m['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex; align-items:flex-end;">
                    <div class="checkbox-row" style="margin-bottom:16px;">
                        <input type="checkbox" id="requiere_verificar_pago" name="requiere_verificar_pago" value="1"
                            <?= $modoEdicion && (int) $pedidoEnEdicion['requiere_verificar_pago'] === 1 ? 'checked' : '' ?>>
                        <label for="requiere_verificar_pago">Requiere verificar pago antes de despachar</label>
                    </div>
                </div>
            </div>

            <div class="campo-grid">
                <div>
                    <label for="costo_envio">Costo de envío / flete</label>
                    <input type="number" id="costo_envio" name="costo_envio" min="0" step="0.01"
                        value="<?= $modoEdicion ? htmlspecialchars((string) $pedidoEnEdicion['costo_envio'], ENT_QUOTES, 'UTF-8') : '0' ?>">
                </div>
                <div>
                    <label for="moneda">Moneda</label>
                    <select id="moneda" name="moneda">
                        <option value="PEN" <?= !$modoEdicion || $pedidoEnEdicion['moneda'] === 'PEN' ? 'selected' : '' ?>>PEN — Soles</option>
                        <option value="USD" <?= $modoEdicion && $pedidoEnEdicion['moneda'] === 'USD' ? 'selected' : '' ?>>USD — Dólares</option>
                    </select>
                </div>
            </div>

            <!-- codigo_orden NO es editable — el backend de edición lo ignora
                 aunque venga seteado. Este campo solo importa en alta manual,
                 donde lo completa la carga desde PDF de TSI (ver más abajo). -->
            <input type="hidden" id="codigo_orden" name="codigo_orden" value="">

            <div class="items-titulo">Productos</div>
            <div id="itemsContenedor"></div>
            <button type="button" class="btn-agregar" id="btnAgregarProducto">+ Agregar producto</button>

            <div>
                <button type="submit" class="btn-primario"><?= htmlspecialchars($textoBotonSubmit, ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </form>
    </div>

    <template id="plantillaItem">
        <div class="item-row">
            <input type="text" name="items[][producto_nombre]" data-field="producto_nombre" placeholder="Producto" required>
            <input type="text" name="items[][variante]" data-field="variante" placeholder="Variante (talla, color…)">
            <input type="text" name="items[][sku]" data-field="sku" placeholder="SKU">
            <input type="number" name="items[][cantidad]" data-field="cantidad" placeholder="Cant." min="1" value="1" required>
            <input type="number" name="items[][precio_unitario]" data-field="precio_unitario" placeholder="Precio" min="0" step="0.01" required>
            <button type="button" class="quitar-item">Quitar</button>
        </div>
    </template>

    <script>
        const contenedor = document.getElementById('itemsContenedor');
        const plantilla = document.getElementById('plantillaItem');

        // Cada fila necesita su propio índice en name="items[N][campo]" — el
        // <template> trae "items[][campo]" (corchetes vacíos) sin índice.
        // PHP arma $_POST['items'] agrupando por posición de "items[]", así
        // que sin este paso cada CAMPO (no cada fila) termina como un
        // elemento suelto del array (bug real: 5 campos x N filas = 5N
        // "productos" vacíos en vez de N productos completos). El contador
        // nunca se reutiliza aunque se borren filas, para que dos filas
        // nunca puedan compartir índice.
        let proximoIndiceItem = 0;

        // prefill (opcional): {producto_nombre, variante, sku, cantidad,
        // precio_unitario} — viene del PDF de TSI (sin variante) o de los
        // items existentes al editar un pedido (con todos los campos). En
        // alta manual normal se llama sin argumento y la fila queda vacía.
        function agregarFilaProducto(prefill) {
            const fragmento = plantilla.content.cloneNode(true);
            const fila = fragmento.querySelector('.item-row');

            const indice = proximoIndiceItem++;
            fila.querySelectorAll('[name^="items[]"]').forEach(input => {
                input.name = input.name.replace('items[]', 'items[' + indice + ']');
            });

            if (prefill) {
                fila.querySelector('[data-field="producto_nombre"]').value = prefill.producto_nombre || '';
                fila.querySelector('[data-field="variante"]').value = prefill.variante || '';
                fila.querySelector('[data-field="sku"]').value = prefill.sku || '';
                fila.querySelector('[data-field="cantidad"]').value = prefill.cantidad || 1;
                fila.querySelector('[data-field="precio_unitario"]').value = (prefill.precio_unitario !== undefined && prefill.precio_unitario !== null) ? prefill.precio_unitario : '';
            }
            fila.querySelector('.quitar-item').addEventListener('click', () => {
                if (contenedor.children.length > 1) {
                    fila.remove();
                }
            });
            contenedor.appendChild(fragmento);
        }

        document.getElementById('btnAgregarProducto').addEventListener('click', () => agregarFilaProducto());

        <?php if ($modoEdicion && !empty($pedidoEnEdicion['items'])): ?>
            const itemsExistentes = <?= json_encode(array_map(static fn (array $it): array => [
                'producto_nombre' => $it['producto_nombre'],
                'variante'        => $it['variante'],
                'sku'             => $it['sku'],
                'cantidad'        => (int) $it['cantidad'],
                'precio_unitario' => (float) $it['precio_unitario'],
            ], $pedidoEnEdicion['items']), JSON_UNESCAPED_UNICODE) ?>;
            itemsExistentes.forEach(item => agregarFilaProducto(item));
        <?php else: ?>
            agregarFilaProducto();
        <?php endif; ?>

        /* ---------- CARGA DESDE PDF DE ORDEN TSI ---------- */
        const API_BASE = <?= json_encode(baseUrl('api/'), JSON_UNESCAPED_SLASHES) ?>;
        const CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;

        const selectCanal = document.getElementById('canal_id');
        const tsiUploadBox = document.getElementById('tsiUploadBox');
        const tsiPdfInput = document.getElementById('tsiPdfInput');
        const tsiUploadMsg = document.getElementById('tsiUploadMsg');

        function esCanalTSI() {
            const opt = selectCanal.options[selectCanal.selectedIndex];
            return !!opt && opt.dataset.codigo === 'TSI';
        }
        function actualizarVisibilidadTSI() {
            tsiUploadBox.style.display = esCanalTSI() ? 'block' : 'none';
        }
        selectCanal.addEventListener('change', actualizarVisibilidadTSI);
        actualizarVisibilidadTSI();

        // Marca visualmente si un campo se completó desde el PDF (verde) o
        // quedó vacío porque el regex correspondiente no matcheó (ámbar) —
        // así el usuario sabe de un vistazo qué revisar antes de confirmar.
        function marcarCampoTSI(id, vinoDelPdf) {
            const el = document.getElementById(id);
            if (!el) return;
            el.style.borderColor = vinoDelPdf ? '#22c55e' : '#f59e0b';
            el.style.background = vinoDelPdf ? '#f0fdf4' : '#fffbeb';
        }

        tsiPdfInput.addEventListener('change', async () => {
            const archivo = tsiPdfInput.files[0];
            if (!archivo) return;

            tsiUploadMsg.textContent = 'Leyendo PDF…';
            tsiUploadMsg.style.color = '#374151';

            const formData = new FormData();
            formData.append('archivo', archivo);
            formData.append('csrf_token', CSRF_TOKEN);

            try {
                const resp = await fetch(API_BASE + 'tsi_extraer_pdf.php', { method: 'POST', body: formData });
                const data = await resp.json();

                if (!data.ok) {
                    // No se toca el formulario — el usuario sigue pudiendo
                    // completarlo a mano sin que nada se haya roto.
                    tsiUploadMsg.textContent = '⚠️ ' + (data.error || 'No se pudo leer el PDF.');
                    tsiUploadMsg.style.color = '#a3231a';
                    return;
                }

                const c = data.campos;
                const faltantes = data.campos_faltantes || [];

                // codigo_orden: se guarda en el hidden pero pedidos_editar.php
                // lo ignora — solo tiene efecto en alta manual (crear).
                document.getElementById('codigo_orden').value = c.codigo_orden || '';
                document.getElementById('cliente_nombre').value = c.cliente_nombre || '';
                document.getElementById('cliente_dni').value = c.cliente_dni || '';
                document.getElementById('cliente_telefono').value = c.cliente_telefono || '';
                document.getElementById('cliente_direccion').value = c.cliente_direccion || '';
                if (c.fecha_limite) {
                    // "YYYY-MM-DD HH:MM:SS" -> "YYYY-MM-DDTHH:MM" (formato de datetime-local).
                    document.getElementById('fecha_limite').value = c.fecha_limite.replace(' ', 'T').slice(0, 16);
                }
                document.getElementById('moneda').value = c.moneda || 'PEN';
                // costo_envio: el PDF de TSI no lo trae — se deja como estaba
                // para que el usuario lo complete/revise a mano.

                ['cliente_nombre', 'cliente_dni', 'cliente_telefono', 'cliente_direccion'].forEach(campo => {
                    marcarCampoTSI(campo, !faltantes.includes(campo));
                });
                marcarCampoTSI('fecha_limite', !faltantes.includes('fecha_limite'));

                if (c.items && c.items.length) {
                    contenedor.innerHTML = '';
                    proximoIndiceItem = 0; // reindexar desde 0 — las filas viejas ya no existen
                    c.items.forEach(item => agregarFilaProducto(item));
                }

                if (faltantes.length) {
                    tsiUploadMsg.textContent = '⚠️ Se cargaron los datos del PDF, pero no se pudieron leer estos campos — complétalos a mano: ' + faltantes.join(', ');
                    tsiUploadMsg.style.color = '#92660a';
                } else {
                    tsiUploadMsg.textContent = '✅ Datos cargados desde el PDF — revisa todo antes de confirmar.';
                    tsiUploadMsg.style.color = '#166534';
                }
            } catch (e) {
                tsiUploadMsg.textContent = '⚠️ Error de red al leer el PDF.';
                tsiUploadMsg.style.color = '#a3231a';
            }
        });
    </script>
