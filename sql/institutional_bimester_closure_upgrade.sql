-- EduSync: cierre institucional formal de bimestres
-- Ejecutar una sola vez por base de datos. Es seguro volver a ejecutar CREATE TABLE.

CREATE TABLE IF NOT EXISTS institutional_bimester_closures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    bimester TINYINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('Cerrado','Reabierto') NOT NULL DEFAULT 'Cerrado',
    closure_type ENUM('Normal','Excepcional') NOT NULL DEFAULT 'Normal',
    reason VARCHAR(500) NULL,
    snapshot_json LONGTEXT NOT NULL,
    closed_by INT NOT NULL,
    closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reopened_by INT NULL,
    reopened_at DATETIME NULL,
    reopen_reason VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_institutional_bimester_version (school_id, academic_year_id, bimester, version),
    KEY idx_institutional_bimester_current (school_id, academic_year_id, bimester, status),
    KEY idx_institutional_bimester_closed_at (school_id, closed_at),
    CONSTRAINT chk_institutional_bimester_number CHECK (bimester BETWEEN 1 AND 4)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
