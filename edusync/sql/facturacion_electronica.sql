-- Tablas para Facturación Electrónica SUNAT

-- Configuración de empresa para facturación
CREATE TABLE IF NOT EXISTS `company_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `school_id` int(11) NOT NULL,
  `ruc` varchar(11) NOT NULL,
  `razon_social` varchar(200) NOT NULL,
  `nombre_comercial` varchar(200) DEFAULT NULL,
  `direccion` varchar(300) NOT NULL,
  `ubigeo` varchar(6) NOT NULL COMMENT 'Código ubigeo SUNAT',
  `urbanizacion` varchar(100) DEFAULT NULL,
  `provincia` varchar(100) NOT NULL,
  `departamento` varchar(100) NOT NULL,
  `distrito` varchar(100) NOT NULL,
  `codigo_pais` varchar(2) DEFAULT 'PE',
  `telefono` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `website` varchar(200) DEFAULT NULL,
  
  -- Certificado digital
  `certificado_path` varchar(300) DEFAULT NULL COMMENT 'Ruta del certificado .pfx',
  `certificado_password` varchar(200) DEFAULT NULL COMMENT 'Password encriptado',
  
  -- Configuración SUNAT
  `sunat_usuario` varchar(50) DEFAULT NULL COMMENT 'Usuario SOL',
  `sunat_password` varchar(200) DEFAULT NULL COMMENT 'Password SOL encriptado',
  `sunat_client_id` varchar(100) DEFAULT NULL COMMENT 'Client ID para autenticación API',
  `sunat_client_secret` varchar(200) DEFAULT NULL COMMENT 'Client Secret encriptado',
  `sunat_modo` enum('beta','produccion') DEFAULT 'beta',
  `sunat_endpoint_factura` varchar(300) DEFAULT NULL,
  `sunat_endpoint_guia` varchar(300) DEFAULT NULL,
  `sunat_endpoint_retension` varchar(300) DEFAULT NULL,
  
  -- Series de comprobantes
  `serie_factura` varchar(4) DEFAULT 'F001',
  `serie_boleta` varchar(4) DEFAULT 'B001',
  `serie_nota_credito` varchar(4) DEFAULT 'FC01',
  `serie_nota_debito` varchar(4) DEFAULT 'FD01',
  
  -- Contadores
  `correlativo_factura` int(11) DEFAULT 1,
  `correlativo_boleta` int(11) DEFAULT 1,
  `correlativo_nota_credito` int(11) DEFAULT 1,
  `correlativo_nota_debito` int(11) DEFAULT 1,
  
  -- Logo y marca de agua
  `logo_path` varchar(300) DEFAULT NULL,
  
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `school_id` (`school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comprobantes electrónicos generados
CREATE TABLE IF NOT EXISTS `comprobantes_electronicos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `school_id` int(11) NOT NULL,
  `payment_id` int(11) DEFAULT NULL COMMENT 'ID del pago relacionado',
  `student_id` int(11) DEFAULT NULL,
  
  -- Tipo de comprobante
  `tipo_comprobante` enum('01','03','07','08') NOT NULL COMMENT '01=Factura, 03=Boleta, 07=NC, 08=ND',
  `serie` varchar(4) NOT NULL,
  `correlativo` int(11) NOT NULL,
  `numero_completo` varchar(20) NOT NULL COMMENT 'Ej: F001-00000123',
  
  -- Datos del cliente
  `cliente_tipo_doc` varchar(1) NOT NULL COMMENT '1=DNI, 6=RUC',
  `cliente_num_doc` varchar(11) NOT NULL,
  `cliente_razon_social` varchar(200) NOT NULL,
  `cliente_direccion` varchar(300) DEFAULT NULL,
  `cliente_email` varchar(100) DEFAULT NULL,
  
  -- Montos
  `moneda` varchar(3) DEFAULT 'PEN',
  `total_operaciones_gravadas` decimal(10,2) DEFAULT 0.00,
  `total_operaciones_exoneradas` decimal(10,2) DEFAULT 0.00,
  `total_operaciones_inafectas` decimal(10,2) DEFAULT 0.00,
  `total_igv` decimal(10,2) DEFAULT 0.00,
  `total_descuentos` decimal(10,2) DEFAULT 0.00,
  `total_valor_venta` decimal(10,2) DEFAULT 0.00,
  `total_precio_venta` decimal(10,2) NOT NULL,
  
  -- Fechas
  `fecha_emision` date NOT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  
  -- Observaciones
  `observaciones` text DEFAULT NULL,
  `leyenda` varchar(200) DEFAULT NULL COMMENT 'Monto en letras',
  
  -- Estado SUNAT
  `estado_sunat` enum('pendiente','aceptado','rechazado','baja','anulado') DEFAULT 'pendiente',
  `codigo_hash` varchar(100) DEFAULT NULL COMMENT 'Hash del comprobante',
  `xml_content` longtext DEFAULT NULL COMMENT 'XML generado',
  `xml_firmado` longtext DEFAULT NULL COMMENT 'XML firmado',
  `cdr_content` longtext DEFAULT NULL COMMENT 'CDR de SUNAT',
  `pdf_path` varchar(300) DEFAULT NULL,
  
  -- Respuesta SUNAT
  `sunat_code` varchar(10) DEFAULT NULL,
  `sunat_description` text DEFAULT NULL,
  `sunat_notes` text DEFAULT NULL,
  `fecha_envio_sunat` datetime DEFAULT NULL,
  `fecha_respuesta_sunat` datetime DEFAULT NULL,
  
  -- Comprobante relacionado (para NC y ND)
  `comprobante_afectado_tipo` varchar(2) DEFAULT NULL,
  `comprobante_afectado_serie` varchar(4) DEFAULT NULL,
  `comprobante_afectado_numero` int(11) DEFAULT NULL,
  `motivo_nota` varchar(200) DEFAULT NULL,
  `tipo_nota_credito` varchar(2) DEFAULT NULL COMMENT '01=Anulación, 07=Devolución',
  
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_completo` (`school_id`, `numero_completo`),
  KEY `payment_id` (`payment_id`),
  KEY `student_id` (`student_id`),
  KEY `estado_sunat` (`estado_sunat`),
  KEY `fecha_emision` (`fecha_emision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Detalle de items de comprobantes
CREATE TABLE IF NOT EXISTS `comprobante_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comprobante_id` int(11) NOT NULL,
  `item` int(11) NOT NULL,
  
  -- Código del producto/servicio
  `codigo_producto` varchar(50) DEFAULT NULL,
  `codigo_sunat` varchar(10) DEFAULT NULL COMMENT 'Código según catálogo SUNAT',
  `descripcion` varchar(500) NOT NULL,
  `unidad_medida` varchar(3) DEFAULT 'NIU' COMMENT 'NIU=Unidad, ZZ=Servicio',
  
  -- Cantidades y precios
  `cantidad` decimal(10,2) NOT NULL DEFAULT 1.00,
  `valor_unitario` decimal(10,2) NOT NULL COMMENT 'Precio sin IGV',
  `precio_unitario` decimal(10,2) NOT NULL COMMENT 'Precio con IGV',
  `descuento` decimal(10,2) DEFAULT 0.00,
  
  -- Totales
  `subtotal` decimal(10,2) NOT NULL,
  `igv` decimal(10,2) NOT NULL,
  `total` decimal(10,2) NOT NULL,
  
  -- Tipo de afectación
  `tipo_afectacion_igv` varchar(2) DEFAULT '10' COMMENT '10=Gravado, 20=Exonerado, 30=Inafecto',
  `porcentaje_igv` decimal(5,2) DEFAULT 18.00,
  
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `comprobante_id` (`comprobante_id`),
  CONSTRAINT `fk_detalle_comprobante` FOREIGN KEY (`comprobante_id`) REFERENCES `comprobantes_electronicos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log de operaciones con SUNAT
CREATE TABLE IF NOT EXISTS `sunat_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comprobante_id` int(11) DEFAULT NULL,
  `operacion` enum('envio','consulta','baja','anulacion') NOT NULL,
  `request` longtext DEFAULT NULL,
  `response` longtext DEFAULT NULL,
  `estado` varchar(20) DEFAULT NULL,
  `mensaje` text DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `comprobante_id` (`comprobante_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agregar columnas en tabla payments para vincular comprobante (solo si no existen)
SET @dbname = DATABASE();
SET @tablename = 'payments';

-- Agregar comprobante_id si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'comprobante_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `payments` ADD COLUMN `comprobante_id` int(11) DEFAULT NULL AFTER `receipt_no`', 'SELECT "Column comprobante_id already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Agregar requiere_factura si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'requiere_factura');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `payments` ADD COLUMN `requiere_factura` tinyint(1) DEFAULT 0 COMMENT "0=Boleta, 1=Factura" AFTER `comprobante_id`', 'SELECT "Column requiere_factura already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Agregar cliente_ruc si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'cliente_ruc');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `payments` ADD COLUMN `cliente_ruc` varchar(11) DEFAULT NULL AFTER `requiere_factura`', 'SELECT "Column cliente_ruc already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Agregar cliente_razon_social si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'cliente_razon_social');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `payments` ADD COLUMN `cliente_razon_social` varchar(200) DEFAULT NULL AFTER `cliente_ruc`', 'SELECT "Column cliente_razon_social already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Agregar cliente_direccion si no existe
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'cliente_direccion');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `payments` ADD COLUMN `cliente_direccion` varchar(300) DEFAULT NULL AFTER `cliente_razon_social`', 'SELECT "Column cliente_direccion already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Agregar índice si no existe
SET @index_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = 'comprobante_id');
SET @sql = IF(@index_exists = 0, 'ALTER TABLE `payments` ADD KEY `comprobante_id` (`comprobante_id`)', 'SELECT "Index comprobante_id already exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Agregar columnas en tabla student_ef_list para vincular comprobante (solo si no existen)
SET @tablename = 'student_ef_list';

-- Agregar comprobante_id si no existe en student_ef_list
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'comprobante_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `student_ef_list` ADD COLUMN `comprobante_id` int(11) DEFAULT NULL AFTER `discounted_amount`', 'SELECT "Column comprobante_id already exists in student_ef_list"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Agregar índice en student_ef_list si no existe
SET @index_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = 'comprobante_id');
SET @sql = IF(@index_exists = 0, 'ALTER TABLE `student_ef_list` ADD KEY `comprobante_id` (`comprobante_id`)', 'SELECT "Index comprobante_id already exists in student_ef_list"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
