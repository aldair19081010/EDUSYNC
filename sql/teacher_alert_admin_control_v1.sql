-- EduSync: control administrativo de avisos docentes.
-- Ejecutar manualmente DESPUÉS de las migraciones de año académico,
-- cierre de notas y notificaciones. No altera estructuras anteriores.
CREATE TABLE IF NOT EXISTS teacher_alert_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    bimester TINYINT UNSIGNED NOT NULL,
    deadline_date DATE NULL,
    notice_days_before TINYINT UNSIGNED NOT NULL DEFAULT 3,
    closure_enabled TINYINT(1) NOT NULL DEFAULT 1,
    incomplete_enabled TINYINT(1) NOT NULL DEFAULT 1,
    admin_edits_enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_teacher_alert_period (school_id, academic_year_id, bimester),
    KEY idx_teacher_alert_year (academic_year_id, school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS teacher_alert_manual_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    bimester TINYINT UNSIGNED NOT NULL,
    teacher_id INT NOT NULL,
    recipient_user_id INT NOT NULL,
    event_id BIGINT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_teacher_manual_cooldown (school_id, academic_year_id, bimester, recipient_user_id, created_at),
    KEY idx_teacher_manual_event (school_id, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
