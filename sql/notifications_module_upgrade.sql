-- Bandeja unificada, archivado y auditoría de notificaciones.
-- Ejecutar MANUALMENTE una sola vez en cada base de datos.

-- Compatibilidad con las notificaciones académicas existentes. Estas estructuras
-- antes se intentaban crear desde las pantallas PHP.
CREATE TABLE IF NOT EXISTS low_grade_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    bimestre VARCHAR(2) NOT NULL,
    count_low_grades INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    teacher_id INT NULL,
    failed_courses TEXT NULL,
    INDEX idx_low_grade_student (student_id),
    INDEX idx_low_grade_teacher (teacher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE low_grade_notifications
    ADD COLUMN IF NOT EXISTS teacher_id INT NULL AFTER is_read,
    ADD COLUMN IF NOT EXISTS failed_courses TEXT NULL AFTER teacher_id;

CREATE TABLE IF NOT EXISTS low_grade_notification_read (
    id INT AUTO_INCREMENT PRIMARY KEY,
    notification_id INT NOT NULL,
    user_id INT NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_low_grade_notification_user (user_id,notification_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS notification_read (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    evaluation_id INT NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_evaluation_notification_user (user_id,evaluation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS notification_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    recipient_user_id INT NULL,
    recipient_role VARCHAR(30) NULL,
    notification_type VARCHAR(40) NOT NULL,
    priority VARCHAR(15) NOT NULL DEFAULT 'Normal',
    title VARCHAR(180) NOT NULL,
    message VARCHAR(500) NULL,
    source_type VARCHAR(40) NULL,
    source_id BIGINT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notification_event_recipient (school_id,recipient_user_id,created_at),
    INDEX idx_notification_event_source (source_type,source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS notification_user_state (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    user_id INT NOT NULL,
    notification_key VARCHAR(100) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    archived_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_notification_user_state (school_id,user_id,notification_key),
    INDEX idx_notification_state_inbox (school_id,user_id,is_archived,is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS notification_audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    user_id INT NOT NULL,
    notification_key VARCHAR(100) NOT NULL,
    action VARCHAR(30) NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notification_audit (school_id,notification_key,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
