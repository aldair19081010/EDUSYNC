-- Mejora integral del módulo de Gestión Académica.
-- Ejecutar una sola vez en cada base de datos de producción.

ALTER TABLE areas
    ADD COLUMN IF NOT EXISTS academic_year_id INT NULL AFTER school_id,
    ADD INDEX IF NOT EXISTS idx_areas_school_year (school_id, academic_year_id);

ALTER TABLE academic_courses
    ADD COLUMN IF NOT EXISTS academic_year_id INT NULL AFTER school_id,
    ADD COLUMN IF NOT EXISTS grades VARCHAR(100) NULL AFTER level,
    ADD COLUMN IF NOT EXISTS course_code VARCHAR(30) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS weekly_hours DECIMAL(4,1) NOT NULL DEFAULT 0 AFTER grades,
    ADD COLUMN IF NOT EXISTS course_type VARCHAR(30) NOT NULL DEFAULT 'Curso' AFTER weekly_hours,
    ADD COLUMN IF NOT EXISTS display_order INT NOT NULL DEFAULT 0 AFTER course_type,
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER display_order,
    ADD COLUMN IF NOT EXISTS course_status VARCHAR(20) NOT NULL DEFAULT 'Activo' AFTER is_active,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD INDEX IF NOT EXISTS idx_academic_courses_year (school_id, academic_year_id),
    ADD INDEX IF NOT EXISTS idx_academic_courses_status (school_id, is_active);

CREATE TABLE IF NOT EXISTS academic_management_audit (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NULL,
    user_id INT NULL,
    entity_type VARCHAR(30) NOT NULL,
    entity_id INT NULL,
    action VARCHAR(40) NOT NULL,
    details LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_academic_audit_school (school_id, created_at),
    INDEX idx_academic_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Asociar los datos existentes al año activo de su colegio.
UPDATE areas a
JOIN academic_year ay ON ay.school_id = a.school_id AND ay.is_active = 1
SET a.academic_year_id = ay.id
WHERE a.academic_year_id IS NULL;

UPDATE academic_courses ac
JOIN academic_year ay ON ay.school_id = ac.school_id AND ay.is_active = 1
SET ac.academic_year_id = ay.id
WHERE ac.academic_year_id IS NULL;

-- Compatibilidad con instalaciones que ya utilizaban is_active.
UPDATE academic_courses
SET course_status = CASE WHEN is_active = 1 THEN 'Activo' ELSE 'Inactivo' END
WHERE course_status IS NULL OR course_status = '';
