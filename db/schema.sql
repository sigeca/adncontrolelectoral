-- Esquema MySQL para el Sistema de Conteo y Transmisión Electoral Seccionales
-- Base de datos: andcontrolelectoral

CREATE DATABASE IF NOT EXISTS `andcontrolelectoral`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `andcontrolelectoral`;

-- 1. Distributivo electoral (cantones, parroquias, zonas, recintos y mesas)
CREATE TABLE IF NOT EXISTS `distributivo` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ubicacion_hash` CHAR(32) NOT NULL,
  `canton` VARCHAR(120) NOT NULL,
  `parroquia` VARCHAR(120) NOT NULL,
  `zona` VARCHAR(120) NOT NULL DEFAULT '',
  `recinto` VARCHAR(255) NOT NULL,
  `mesas_fem_inicio` INT NOT NULL DEFAULT 0,
  `mesas_fem_fin` INT NOT NULL DEFAULT 0,
  `mesas_masc_inicio` INT NOT NULL DEFAULT 0,
  `mesas_masc_fin` INT NOT NULL DEFAULT 0,
  `padron_mesa` INT NOT NULL DEFAULT 350,
  `electores_recinto` INT NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_distributivo_hash` (`ubicacion_hash`),
  KEY `idx_dist_geo` (`canton`, `parroquia`, `recinto`(120))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Candidatos y listas
CREATE TABLE IF NOT EXISTS `candidatos` (
  `id` VARCHAR(64) NOT NULL,
  `dignidad` VARCHAR(80) NOT NULL,
  `lista` VARCHAR(64) NOT NULL DEFAULT '',
  `partido` VARCHAR(120) NOT NULL DEFAULT '',
  `color` VARCHAR(32) NOT NULL DEFAULT '#2563eb',
  `nombre` VARCHAR(255) NOT NULL,
  `canton` VARCHAR(120) NOT NULL DEFAULT '',
  `parroquia` VARCHAR(120) NOT NULL DEFAULT '',
  `circunscripcion` VARCHAR(120) NOT NULL DEFAULT '',
  `orden_visual` INT NOT NULL DEFAULT 0,
  `extra_json` LONGTEXT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cand_dignidad_canton` (`dignidad`, `canton`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Orden de candidatos dentro de una lista pluripersonal (ej. Concejales / Juntas)
CREATE TABLE IF NOT EXISTS `candidatos_orden` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `candidato_id` VARCHAR(64) NOT NULL,
  `orden` INT NOT NULL,
  `nombre` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cand_orden` (`candidato_id`, `orden`),
  CONSTRAINT `fk_cand_orden_candidato`
    FOREIGN KEY (`candidato_id`) REFERENCES `candidatos` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Delegados y personal de recinto/mesa
CREATE TABLE IF NOT EXISTS `delegados` (
  `id` VARCHAR(64) NOT NULL,
  `distributivo_id` INT UNSIGNED NULL,
  `apellidos` VARCHAR(150) NOT NULL DEFAULT '',
  `nombres` VARCHAR(150) NOT NULL DEFAULT '',
  `nombre` VARCHAR(255) NOT NULL,
  `cedula` VARCHAR(20) NOT NULL DEFAULT '',
  `telefono` VARCHAR(32) NOT NULL DEFAULT '',
  `rol` VARCHAR(64) NOT NULL DEFAULT 'DELEGADO_TITULAR',
  `acreditado` VARCHAR(32) NOT NULL DEFAULT 'PENDIENTE',
  `canton` VARCHAR(120) NOT NULL DEFAULT '',
  `parroquia` VARCHAR(120) NOT NULL DEFAULT '',
  `zona` VARCHAR(120) NOT NULL DEFAULT '',
  `recinto` VARCHAR(255) NOT NULL DEFAULT '',
  `mesa` VARCHAR(120) NOT NULL DEFAULT '',
  `fecha` VARCHAR(64) NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_deleg_cedula` (`cedula`),
  KEY `idx_deleg_geo` (`canton`, `parroquia`, `recinto`(120)),
  KEY `idx_deleg_dist` (`distributivo_id`),
  CONSTRAINT `fk_delegados_distributivo`
    FOREIGN KEY (`distributivo_id`) REFERENCES `distributivo` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Actas escrutadas
CREATE TABLE IF NOT EXISTS `actas` (
  `acta_key` VARCHAR(255) NOT NULL,
  `distributivo_id` INT UNSIGNED NULL,
  `dignidad` VARCHAR(80) NOT NULL,
  `canton` VARCHAR(120) NOT NULL,
  `parroquia` VARCHAR(120) NOT NULL,
  `zona` VARCHAR(120) NOT NULL DEFAULT '',
  `recinto` VARCHAR(255) NOT NULL,
  `mesa` VARCHAR(80) NOT NULL,
  `total_papeletas` INT NOT NULL DEFAULT 0,
  `validos` INT NOT NULL DEFAULT 0,
  `blancos` INT NOT NULL DEFAULT 0,
  `nulos` INT NOT NULL DEFAULT 0,
  `total_votos` INT NOT NULL DEFAULT 0,
  `diferencia` INT NOT NULL DEFAULT 0,
  `cuadrada` TINYINT(1) NOT NULL DEFAULT 0,
  `observaciones` TEXT NULL,
  `foto_acta` LONGTEXT NULL,
  `foto_borrador` LONGTEXT NULL,
  `timestamp` VARCHAR(64) NULL,
  `extra_json` LONGTEXT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`acta_key`),
  KEY `idx_actas_geo` (`dignidad`, `canton`, `parroquia`),
  KEY `idx_actas_dist` (`distributivo_id`),
  CONSTRAINT `fk_actas_distributivo`
    FOREIGN KEY (`distributivo_id`) REFERENCES `distributivo` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Detalle de votos por candidato en cada acta (relación N:M actas <-> candidatos)
CREATE TABLE IF NOT EXISTS `acta_votos` (
  `acta_key` VARCHAR(255) NOT NULL,
  `candidato_id` VARCHAR(64) NOT NULL,
  `votos` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`acta_key`, `candidato_id`),
  KEY `idx_acta_votos_cand` (`candidato_id`),
  CONSTRAINT `fk_acta_votos_acta`
    FOREIGN KEY (`acta_key`) REFERENCES `actas` (`acta_key`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_acta_votos_candidato`
    FOREIGN KEY (`candidato_id`) REFERENCES `candidatos` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Respaldos gráficos de escrutinio (fotos enviadas por delegados)
CREATE TABLE IF NOT EXISTS `respaldos` (
  `resp_key` VARCHAR(255) NOT NULL,
  `distributivo_id` INT UNSIGNED NULL,
  `dignidad` VARCHAR(80) NOT NULL DEFAULT '',
  `canton` VARCHAR(120) NOT NULL DEFAULT '',
  `parroquia` VARCHAR(120) NOT NULL DEFAULT '',
  `zona` VARCHAR(120) NOT NULL DEFAULT '',
  `recinto` VARCHAR(255) NOT NULL DEFAULT '',
  `mesa` VARCHAR(80) NOT NULL DEFAULT '',
  `foto_acta` LONGTEXT NULL,
  `foto_borrador` LONGTEXT NULL,
  `timestamp` VARCHAR(64) NULL,
  `payload_json` LONGTEXT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`resp_key`),
  KEY `idx_resp_dist` (`distributivo_id`),
  CONSTRAINT `fk_respaldos_distributivo`
    FOREIGN KEY (`distributivo_id`) REFERENCES `distributivo` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Tarifas por rol de personal
CREATE TABLE IF NOT EXISTS `tarifas` (
  `rol_key` VARCHAR(32) NOT NULL,
  `monto` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rol_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Pagos a delegados y coordinadores (1:1 con delegados)
CREATE TABLE IF NOT EXISTS `pagos` (
  `delegado_id` VARCHAR(64) NOT NULL,
  `monto` DECIMAL(10,2) NULL,
  `estado` VARCHAR(32) NOT NULL DEFAULT 'PENDIENTE',
  `metodo` VARCHAR(64) NULL,
  `ref` VARCHAR(128) NULL,
  `fecha` VARCHAR(32) NULL,
  `observaciones` TEXT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`delegado_id`),
  CONSTRAINT `fk_pagos_delegado`
    FOREIGN KEY (`delegado_id`) REFERENCES `delegados` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Usuarios del sistema (control de acceso y territorio)
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` VARCHAR(64) NOT NULL,
  `nom` VARCHAR(120) NOT NULL DEFAULT '',
  `ape` VARCHAR(120) NOT NULL DEFAULT '',
  `ced` VARCHAR(20) NOT NULL DEFAULT '',
  `tel` VARCHAR(32) NOT NULL DEFAULT '',
  `mail` VARCHAR(150) NOT NULL DEFAULT '',
  `user` VARCHAR(100) NOT NULL,
  `salt` VARCHAR(128) NOT NULL DEFAULT '',
  `hash` VARCHAR(255) NOT NULL DEFAULT '',
  `rol` VARCHAR(32) NOT NULL DEFAULT 'digitador',
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `bloq` TINYINT(1) NOT NULL DEFAULT 0,
  `cambio` TINYINT(1) NOT NULL DEFAULT 0,
  `ultimo` VARCHAR(64) NULL,
  `canton` VARCHAR(120) NOT NULL DEFAULT '',
  `parroquia` VARCHAR(120) NOT NULL DEFAULT '',
  `recinto` VARCHAR(255) NOT NULL DEFAULT '',
  `mesa` VARCHAR(120) NOT NULL DEFAULT '',
  `ver_pref` TINYINT(1) NOT NULL DEFAULT 0,
  `cedpw` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_usuarios_user` (`user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Auditoría de acciones
CREATE TABLE IF NOT EXISTS `auditoria` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `t` VARCHAR(64) NOT NULL,
  `usr` VARCHAR(100) NOT NULL DEFAULT '',
  `op` VARCHAR(120) NOT NULL DEFAULT '',
  `det` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_auditoria_t` (`t`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tarifas iniciales por defecto
INSERT IGNORE INTO `tarifas` (`rol_key`, `monto`) VALUES
  ('MESA', 25.00),
  ('COORD', 35.00),
  ('ESCANER', 30.00);

-- 12. Alimentación de personal
CREATE TABLE IF NOT EXISTS `alimentacion` (
  `item_key` VARCHAR(255) NOT NULL,
  `payload_json` LONGTEXT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Notificaciones del sistema
CREATE TABLE IF NOT EXISTS `notificaciones` (
  `notif_key` VARCHAR(255) NOT NULL,
  `payload_json` LONGTEXT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`notif_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Configuración general (JSON, ej. ocr_cantones)
CREATE TABLE IF NOT EXISTS `configuracion` (
  `cfg_key` VARCHAR(64) NOT NULL,
  `cfg_value` LONGTEXT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`cfg_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
