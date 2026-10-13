-- EduSync: dispositivos de administradores, docentes y auxiliares.
-- Ejecutar una sola vez de forma manual ANTES de habilitar la app para esos roles.
-- No modifica los tokens ni las notificaciones de estudiantes.
CREATE TABLE IF NOT EXISTS staff_device_tokens (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 school_id INT NOT NULL,
 user_id INT NOT NULL,
 role_type TINYINT NOT NULL,
 device_id VARCHAR(128) NOT NULL,
 fcm_token VARCHAR(1024) NOT NULL,
 token_hash CHAR(64) NOT NULL,
 platform VARCHAR(12) NOT NULL DEFAULT 'android',
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_staff_fcm_hash (token_hash),
 UNIQUE KEY uq_staff_device_account (school_id,user_id,device_id),
 KEY idx_staff_account_active (school_id,user_id,is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS staff_push_delivery (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 school_id INT NOT NULL,
 notification_event_id BIGINT NOT NULL,
 device_token_id BIGINT NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'pending',
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 provider_code INT NOT NULL DEFAULT 0,
 provider_message VARCHAR(500) NULL,
 next_retry_at DATETIME NULL,
 delivered_at DATETIME NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_staff_event_device (notification_event_id,device_token_id),
 KEY idx_staff_delivery_retry (status,next_retry_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
