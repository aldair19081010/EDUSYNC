-- Ciclo de vida, vencimientos y auditoría para el módulo Asignar Deudas.
-- Ejecutar manualmente una sola vez en cada base de datos.

ALTER TABLE student_ef_list
    ADD COLUMN debt_status VARCHAR(20) NOT NULL DEFAULT 'Activa' AFTER comprobante_id,
    ADD COLUMN issue_date DATE NULL AFTER debt_status,
    ADD COLUMN due_date DATE NULL AFTER issue_date,
    ADD COLUMN billing_period VARCHAR(40) NULL AFTER due_date,
    ADD COLUMN installment_number INT NULL AFTER billing_period,
    ADD COLUMN notes TEXT NULL AFTER installment_number,
    ADD COLUMN cancelled_at DATETIME NULL AFTER notes,
    ADD COLUMN cancelled_by INT NULL AFTER cancelled_at,
    ADD COLUMN cancellation_reason VARCHAR(255) NULL AFTER cancelled_by,
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER cancellation_reason,
    ADD INDEX idx_debt_status_due (debt_status, due_date),
    ADD INDEX idx_debt_student_course (student_id, course_id);

UPDATE student_ef_list
SET issue_date = DATE(date_created)
WHERE issue_date IS NULL;

CREATE TABLE IF NOT EXISTS debt_audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NULL,
    debt_id INT NULL,
    student_id INT NULL,
    course_id INT NULL,
    user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    details LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_debt_audit_school_date (school_id, created_at),
    INDEX idx_debt_audit_debt (debt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
