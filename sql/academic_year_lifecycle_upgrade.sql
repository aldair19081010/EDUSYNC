-- Ciclo de vida y periodos configurables para Años Académicos.
-- Ejecutar una vez. El API también crea estas estructuras de forma compatible.

ALTER TABLE academic_year
    ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'Borrador' AFTER is_active,
    ADD COLUMN IF NOT EXISTS period_type VARCHAR(20) NOT NULL DEFAULT 'Bimestre' AFTER status,
    ADD COLUMN IF NOT EXISTS closed_at DATETIME NULL AFTER end_date,
    ADD COLUMN IF NOT EXISTS closed_by INT NULL AFTER closed_at,
    ADD COLUMN IF NOT EXISTS close_notes TEXT NULL AFTER closed_by,
    ADD COLUMN IF NOT EXISTS reopened_at DATETIME NULL AFTER close_notes,
    ADD COLUMN IF NOT EXISTS reopened_by INT NULL AFTER reopened_at,
    ADD COLUMN IF NOT EXISTS reopen_reason TEXT NULL AFTER reopened_by;

-- Conservar como Activo el año que ya estaba operativo antes de la migración.
UPDATE academic_year
SET status = 'Activo'
WHERE is_active = 1;

-- Normalizar únicamente registros antiguos que todavía no tengan estado.
UPDATE academic_year
SET status = 'Borrador'
WHERE is_active = 0 AND (status IS NULL OR status = '');

CREATE TABLE IF NOT EXISTS academic_periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    period_number INT NOT NULL,
    name VARCHAR(80) NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_academic_period (school_id, academic_year_id, period_number),
    INDEX idx_academic_period_year (academic_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Si academic_periods ya fue creada por una versión anterior, flexibilizar las fechas.
ALTER TABLE academic_periods
    MODIFY COLUMN start_date DATE NULL,
    MODIFY COLUMN end_date DATE NULL;

-- Compatibilidad con los bloqueos que ya utiliza el módulo de evaluaciones.
CREATE TABLE IF NOT EXISTS bimester_locks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT NOT NULL,
    school_id INT NOT NULL,
    bimester TINYINT NOT NULL,
    is_locked TINYINT NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_year_school_bim (academic_year_id, school_id, bimester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS academic_year_audit (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NULL,
    user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    details LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_aya_school_date (school_id, created_at),
    INDEX idx_aya_year (academic_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
