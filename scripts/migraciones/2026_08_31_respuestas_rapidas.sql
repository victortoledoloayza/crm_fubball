-- scripts/migraciones/2026_08_31_respuestas_rapidas.sql
--
-- Agrega soporte de "Respuestas Rápidas" (panel admin + extensión Chrome
-- vía api/webhooks/respuestas_rapidas.php).
--
-- Uso en producción:
--   mysql -u USUARIO -p NOMBRE_BD < scripts/migraciones/2026_08_31_respuestas_rapidas.sql
-- o impórtalo vía phpMyAdmin si el hosting no da acceso a línea de comandos.
--
-- Ya se corrió contra la base de datos local de desarrollo (fubball_kds).
-- Usa CREATE TABLE IF NOT EXISTS: correrlo dos veces por error no rompe
-- nada, pero no reemplaza un ALTER si la tabla ya existe con otra
-- estructura.
--
-- Requiere que ya existan `canales` y `usuarios` (Fase 0 del proyecto).

CREATE TABLE IF NOT EXISTS respuestas_rapidas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  titulo VARCHAR(150) NOT NULL,
  texto TEXT NOT NULL,
  -- NULL = aplica a cualquier canal. Con valor = específica de un canal
  -- (mismo patrón que pedidos.canal_id).
  canal_id TINYINT UNSIGNED DEFAULT NULL,
  orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  -- NULL cuando se crea vía token (extensión), sin usuario logueado detrás.
  creado_por INT UNSIGNED DEFAULT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_respuestas_activo_orden (activo, orden),
  KEY fk_respuestas_canal (canal_id),
  KEY fk_respuestas_creador (creado_por),
  CONSTRAINT fk_respuestas_canal FOREIGN KEY (canal_id) REFERENCES canales (id),
  CONSTRAINT fk_respuestas_creador FOREIGN KEY (creado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS respuestas_rapidas_adjuntos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  respuesta_id INT UNSIGNED NOT NULL,
  tipo ENUM('imagen','video','pdf','audio','documento') NOT NULL,
  -- Ruta relativa a la raíz del proyecto (ej. 'uploads/respuestas_rapidas/xxx.png').
  -- La URL pública se arma con baseUrl(path) al leer, no se guarda absoluta.
  path VARCHAR(500) NOT NULL,
  nombre_archivo VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY fk_adjuntos_respuesta (respuesta_id),
  CONSTRAINT fk_adjuntos_respuesta FOREIGN KEY (respuesta_id)
    REFERENCES respuestas_rapidas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
