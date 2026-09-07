-- Conservación histórica, anulación y auditoría del módulo de evaluaciones/notas.
-- Ejecutar manualmente una sola vez en cada base de datos.

ALTER TABLE evaluations
    ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'Activa' AFTER academic_year_id,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER status,
    ADD COLUMN IF NOT EXISTS annulled_at DATETIME NULL AFTER updated_at,
    ADD COLUMN IF NOT EXISTS annulled_by INT NULL AFTER annulled_at,
    ADD COLUMN IF NOT EXISTS annulment_reason VARCHAR(255) NULL AFTER annulled_by,
    ADD INDEX IF NOT EXISTS idx_evaluation_status (status, academic_year_id),
    ADD INDEX IF NOT EXISTS idx_evaluation_teacher_course (teacher_course_id, teacher_id);

CREATE TABLE IF NOT EXISTS evaluation_audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NULL,
    evaluation_id INT NULL,
    teacher_id INT NULL,
    user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    details LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_evaluation_audit_school (school_id, created_at),
    INDEX idx_evaluation_audit_evaluation (evaluation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
