<?php
/**
 * pedidos_nuevo.php
 *
 * Alta manual de pedidos: mientras no hay integraciones reales (Shopify /
 * extensión llegan en Fase 7), sirve para cargar pedidos de prueba y para
 * registrar a mano un pedido tomado por teléfono.
 */

require_once __DIR__ . '/core/bootstrap.php';

Auth::requireLogin();

$pdo = Database::getConnection();
$canales = $pdo->query('SELECT id, nombre FROM canales WHERE activo = 1 ORDER BY nombre')->fetchAll();
$metodosDespacho = $pdo->query('SELECT id, nombre FROM metodos_despacho WHERE activo = 1 ORDER BY nombre')->fetchAll();

$error = $_GET['error'] ?? '';

$tituloPagina = 'Nuevo pedido';
$navActiva    = 'tablero';
require __DIR__ . '/core/ui/layout_header.php';
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
    </style>

    <?php if ($error !== ''): ?>
        <div class="mensaje-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="form-card">
        <h1>Nuevo pedido</h1>
        <p class="subtitulo">Carga manual — úsalo para pedidos tomados por teléfono o para pruebas del tablero.</p>

        <form method="post" action="<?= htmlspecialchars(baseUrl('api/pedidos_crear.php'), ENT_QUOTES, 'UTF-8') ?>" id="formPedido">
            <?= csrfCampo() ?>

            <div class="campo-grid">
                <div>
                    <label for="canal_id">Canal</label>
                    <select id="canal_id" name="canal_id" required>
                        <option value="">— Selecciona —</option>
                        <?php foreach ($canales as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="fecha_limite">Fecha límite de despacho</label>
                    <input type="datetime-local" id="fecha_limite" name="fecha_limite" required>
                </div>
            </div>

            <div class="campo-grid">
                <div>
                    <label for="cliente_nombre">Nombre del cliente</label>
                    <input type="text" id="cliente_nombre" name="cliente_nombre" required>
                </div>
                <div>
                    <label for="cliente_dni">DNI</label>
                    <input type="text" id="cliente_dni" name="cliente_dni">
                </div>
            </div>

            <div class="campo-grid">
                <div>
                    <label for="cliente_telefono">Teléfono</label>
                    <input type="text" id="cliente_telefono" name="cliente_telefono">
                </div>
                <div>
                    <label for="cliente_email">Email (opcional)</label>
                    <input type="email" id="cliente_email" name="cliente_email">
                </div>
            </div>

            <label for="cliente_direccion">Dirección</label>
            <input type="text" id="cliente_direccion" name="cliente_direccion">

            <div class="campo-grid">
                <div>
                    <label for="metodo_despacho_id">Método de despacho</label>
                    <select id="metodo_despacho_id" name="metodo_despacho_id">
                        <option value="">— Selecciona —</option>
                        <?php foreach ($metodosDespacho as $m): ?>
                            <option value="<?= (int) $m['id'] ?>"><?= htmlspecialchars($m['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex; align-items:flex-end;">
                    <div class="checkbox-row" style="margin-bottom:16px;">
                        <input type="checkbox" id="requiere_verificar_pago" name="requiere_verificar_pago" value="1">
                        <label for="requiere_verificar_pago">Requiere verificar pago antes de despachar</label>
                    </div>
                </div>
            </div>

            <div class="items-titulo">Productos</div>
            <div id="itemsContenedor"></div>
            <button type="button" class="btn-agregar" id="btnAgregarProducto">+ Agregar producto</button>

            <div>
                <button type="submit" class="btn-primario">Crear pedido</button>
            </div>
        </form>
    </div>

    <template id="plantillaItem">
        <div class="item-row">
            <input type="text" name="items[][producto_nombre]" placeholder="Producto" required>
            <input type="text" name="items[][variante]" placeholder="Variante (talla, color…)">
            <input type="text" name="items[][sku]" placeholder="SKU">
            <input type="number" name="items[][cantidad]" placeholder="Cant." min="1" value="1" required>
            <input type="number" name="items[][precio_unitario]" placeholder="Precio" min="0" step="0.01" required>
            <button type="button" class="quitar-item">Quitar</button>
        </div>
    </template>

    <script>
        const contenedor = document.getElementById('itemsContenedor');
        const plantilla = document.getElementById('plantillaItem');

        function agregarFilaProducto() {
            const fragmento = plantilla.content.cloneNode(true);
            const fila = fragmento.querySelector('.item-row');
            fila.querySelector('.quitar-item').addEventListener('click', () => {
                if (contenedor.children.length > 1) {
                    fila.remove();
                }
            });
            contenedor.appendChild(fragmento);
        }

        document.getElementById('btnAgregarProducto').addEventListener('click', agregarFilaProducto);
        agregarFilaProducto();
    </script>
<?php
require __DIR__ . '/core/ui/layout_footer.php';
