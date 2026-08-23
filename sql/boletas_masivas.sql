-- Tabla para almacenar boletas generadas de forma masiva
CREATE TABLE IF NOT EXISTS `boletas_masivas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `school_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `concepto` varchar(255) NOT NULL,
  `monto` decimal(10, 2) NOT NULL,
  `periodo` varchar(100) NOT NULL COMMENT 'Año académico o período',
  `comprobante_id` int(11) DEFAULT NULL COMMENT 'FK a comprobantes_electronicos',
  `numero_completo` varchar(50) DEFAULT NULL COMMENT 'Número generado por SUNAT (B001-00000001)',
  `estado_sunat` varchar(50) DEFAULT 'pendiente' COMMENT 'pendiente, aceptado, rechazado',
  `codigo_hash` text,
  `fecha_creacion` timestamp DEFAULT CURRENT_TIMESTAMP,
  `fecha_emision` datetime DEFAULT NULL,
  `observaciones` text,
  PRIMARY KEY (`id`),
  KEY `school_id` (`school_id`),
  KEY `student_id` (`student_id`),
  KEY `comprobante_id` (`comprobante_id`),
  KEY `periodo` (`periodo`),
  KEY `estado_sunat` (`estado_sunat`),
  KEY `fecha_creacion` (`fecha_creacion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

