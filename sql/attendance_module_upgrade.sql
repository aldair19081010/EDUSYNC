-- Gestión integral y trazable de asistencia.
-- Ejecutar MANUALMENTE una sola vez en cada base de datos.

ALTER TABLE asistencia
    MODIFY estado VARCHAR(40) NOT NULL,
    ADD COLUMN IF NOT EXISTS school_id INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS academic_year_id INT NULL AFTER school_id,
    ADD COLUMN IF NOT EXISTS source VARCHAR(20) NOT NULL DEFAULT 'Manual' AFTER estado,
    ADD COLUMN IF NOT EXISTS notes VARCHAR(500) NULL AFTER source,
    ADD COLUMN IF NOT EXISTS justification_status VARCHAR(20) NULL AFTER notes,
    ADD COLUMN IF NOT EXISTS justification_reason VARCHAR(255) NULL AFTER justification_status,
    ADD COLUMN IF NOT EXISTS justification_file VARCHAR(255) NULL AFTER justification_reason,
    ADD COLUMN IF NOT EXISTS justified_at DATETIME NULL AFTER justification_file,
    ADD COLUMN IF NOT EXISTS justified_by INT NULL AFTER justified_at,
    ADD COLUMN IF NOT EXISTS is_cancelled TINYINT(1) NOT NULL DEFAULT 0 AFTER justified_by,
    ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL AFTER is_cancelled,
    ADD COLUMN IF NOT EXISTS cancelled_by INT NULL AFTER cancelled_at,
    ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(255) NULL AFTER cancelled_by,
    ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER cancellation_reason,
    ADD COLUMN IF NOT EXISTS updated_by INT NULL AFTER created_by,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER updated_by,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD INDEX IF NOT EXISTS idx_attendance_school_date (school_id,fecha),
    ADD INDEX IF NOT EXISTS idx_attendance_year (academic_year_id),
    ADD INDEX IF NOT EXISTS idx_attendance_class (school_id,fecha,tipo,is_cancelled);

UPDATE asistencia a
INNER JOIN student s ON s.id=a.student_id
SET a.school_id=s.school_id
WHERE a.school_id IS NULL;

UPDATE asistencia a
INNER JOIN academic_year ay ON ay.school_id=a.school_id AND a.fecha BETWEEN ay.start_date AND ay.end_date
SET a.academic_year_id=ay.id
WHERE a.academic_year_id IS NULL;

ALTER TABLE attendance_rules
    ADD COLUMN IF NOT EXISTS school_id INT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS entry_time TIME NULL AFTER day_name_es,
    ADD COLUMN IF NOT EXISTS tolerance_minutes INT NOT NULL DEFAULT 0 AFTER entry_time,
    ADD COLUMN IF NOT EXISTS exit_time TIME NULL AFTER tolerance_minutes,
    ADD COLUMN IF NOT EXISTS early_exit_limit TIME NULL AFTER exit_time;

-- Sustituir la unicidad global anterior por una configuración independiente por colegio.
DROP INDEX IF EXISTS day_of_week ON attendance_rules;
DROP INDEX IF EXISTS uniq_attendance_rule_school_day ON attendance_rules;
ALTER TABLE attendance_rules
    ADD UNIQUE KEY uniq_attendance_rule_school_day (school_id,day_of_week);

UPDATE attendance_rules
SET entry_time=COALESCE(entry_time,early_time),
    tolerance_minutes=GREATEST(TIMESTAMPDIFF(MINUTE,early_time,late_time),0),
    exit_time=COALESCE(exit_time,'15:00:00'),
    early_exit_limit=COALESCE(early_exit_limit,'14:45:00');

ALTER TABLE attendance_settings
    ADD COLUMN IF NOT EXISTS school_id INT NULL AFTER id;

DROP INDEX IF EXISTS setting_key ON attendance_settings;
DROP INDEX IF EXISTS uniq_attendance_setting_school_key ON attendance_settings;
ALTER TABLE attendance_settings
    ADD UNIQUE KEY uniq_attendance_setting_school_key (school_id,setting_key);

CREATE TABLE IF NOT EXISTS attendance_day_closures (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NULL,
    attendance_date DATE NOT NULL,
    nivel VARCHAR(30) NOT NULL,
    grado VARCHAR(30) NOT NULL,
    seccion VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Cerrada',
    closed_at DATETIME NOT NULL,
    closed_by INT NOT NULL,
    reopened_at DATETIME NULL,
    reopened_by INT NULL,
    reopen_reason VARCHAR(255) NULL,
    UNIQUE KEY uniq_attendance_closure (school_id,attendance_date,nivel,grado,seccion),
    INDEX idx_attendance_closure_date (school_id,attendance_date,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS attendance_calendar (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NULL,
    calendar_date DATE NOT NULL,
    name VARCHAR(120) NOT NULL,
    day_type VARCHAR(30) NOT NULL DEFAULT 'No laborable',
    notes VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_attendance_calendar (school_id,calendar_date),
    INDEX idx_attendance_calendar_year (school_id,academic_year_id,calendar_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS attendance_audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    attendance_id INT NULL,
    student_id INT NULL,
    user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    details LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attendance_audit_school (school_id,created_at),
    INDEX idx_attendance_audit_record (attendance_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Solicitudes de auxiliares que deben ser revisadas por administración.
CREATE TABLE IF NOT EXISTS attendance_change_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    attendance_id INT NULL,
    requested_by INT NOT NULL,
    request_type VARCHAR(30) NOT NULL,
    request_payload LONGTEXT NOT NULL,
    reason VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Pendiente',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_attendance_request_school (school_id,status,created_at),
    INDEX idx_attendance_request_record (attendance_id,status),
    INDEX idx_attendance_request_user (requested_by,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
