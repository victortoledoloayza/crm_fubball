-- scripts/migraciones/2026_09_03_tsi_costo_envio.sql
--
-- Agrega el canal TSI y los campos costo_envio/moneda de pedidos, para el
-- alta manual desde PDF de Orden de Pedido de TSI (pedidos_nuevo.php) y
-- el generador de etiquetas de despacho (core/pedidos/EtiquetaGenerator.php).
--
-- Uso en producción:
--   mysql -u USUARIO -p NOMBRE_BD < scripts/migraciones/2026_09_03_tsi_costo_envio.sql
-- o impórtalo vía phpMyAdmin si el hosting no da acceso a línea de comandos.
--
-- Ya se corrió contra la base de datos local de desarrollo (fubball_kds).
--
-- El INSERT es idempotente (INSERT IGNORE, por el UNIQUE de canales.codigo)
-- — correrlo dos veces no duplica el canal. El ALTER TABLE NO es
-- idempotente (MySQL simple no soporta "ADD COLUMN IF NOT EXISTS" de forma
-- portable): si ya corriste este archivo antes, comenta o borra el bloque
-- ALTER y dejá solo el INSERT — correrlo dos veces falla con
-- "Duplicate column name", pero no rompe nada que ya se haya aplicado.
--
-- Requiere que ya exista `canales` y `pedidos` (Fase 0 del proyecto).

INSERT IGNORE INTO canales (codigo, nombre, color_hex, activo)
VALUES ('TSI', 'TSI (ERP)', '#4361ee', 1);

ALTER TABLE pedidos
  ADD COLUMN costo_envio DECIMAL(10,2) NULL DEFAULT 0 AFTER comision_plataforma,
  ADD COLUMN moneda VARCHAR(3) NOT NULL DEFAULT 'PEN' AFTER costo_envio;
