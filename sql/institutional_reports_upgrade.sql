-- Auditoría para Fichas y Reportes Institucionales.
-- Ejecutar manualmente una sola vez en cada base de datos.

CREATE TABLE IF NOT EXISTS institutional_report_audit (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NULL,
    user_id INT NULL,
    report_type VARCHAR(50) NOT NULL,
    entity_id INT NULL,
    details LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_institutional_report_school (school_id, created_at),
    INDEX idx_institutional_report_type (report_type, entity_id),
    INDEX idx_institutional_report_year (academic_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
