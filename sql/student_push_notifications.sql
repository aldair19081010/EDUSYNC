-- Notificaciones push para estudiantes (Entrada / Salida)
-- Ejecutar MANUALMENTE una sola vez en cada base de datos de EduSync.

CREATE TABLE IF NOT EXISTS student_device_tokens (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    device_id VARCHAR(128) NOT NULL,
    fcm_token VARCHAR(1024) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    platform VARCHAR(20) NOT NULL DEFAULT 'android',
    app_version VARCHAR(40) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_student_device (school_id, student_id, device_id),
    UNIQUE KEY uniq_fcm_token_hash (token_hash),
    INDEX idx_student_push_active (school_id, student_id, is_active),
    INDEX idx_push_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS push_notification_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    attendance_id INT NULL,
    notification_type VARCHAR(40) NOT NULL,
    device_token_id BIGINT NULL,
    delivery_status VARCHAR(20) NOT NULL,
    provider_code INT NULL,
    provider_message VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_push_log_student (school_id, student_id, created_at),
    INDEX idx_push_log_attendance (attendance_id),
    INDEX idx_push_log_status (delivery_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Centro de notificaciones del estudiante.
CREATE TABLE IF NOT EXISTS student_notification_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    notification_type VARCHAR(40) NOT NULL,
    title VARCHAR(160) NOT NULL,
    body VARCHAR(600) NOT NULL,
    screen VARCHAR(40) NOT NULL DEFAULT '',
    entity_type VARCHAR(40) NULL,
    entity_id BIGINT NULL,
    dedupe_key VARCHAR(160) NULL,
    data_json LONGTEXT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    UNIQUE KEY uniq_student_notification_dedupe (school_id, student_id, dedupe_key),
    INDEX idx_student_notification_feed (school_id, student_id, created_at),
    INDEX idx_student_notification_unread (school_id, student_id, is_read, created_at),
    INDEX idx_student_notification_type (notification_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
