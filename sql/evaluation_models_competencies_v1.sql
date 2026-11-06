-- EduSync: modelo dual de evaluación y competencias curriculares por área.
-- Ejecutar una sola vez después de respaldar la base de datos.
-- Los cursos existentes quedan en 'weighted' para preservar su comportamiento actual.

ALTER TABLE academic_courses
    ADD COLUMN IF NOT EXISTS evaluation_model VARCHAR(20) NOT NULL DEFAULT 'weighted' AFTER course_type;

CREATE TABLE IF NOT EXISTS area_competencies (
    id INT NOT NULL AUTO_INCREMENT,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    area_id INT NOT NULL,
    level VARCHAR(20) NOT NULL,
    name VARCHAR(255) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_area_competency_name (school_id, academic_year_id, area_id, level, name),
    KEY idx_area_competencies_lookup (school_id, academic_year_id, area_id, level, is_active, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La relación para el nuevo modelo es independiente de evaluation_competencias,
-- que se conserva para los componentes ponderados y el historial.
CREATE TABLE IF NOT EXISTS evaluation_area_competencies (
    id BIGINT NOT NULL AUTO_INCREMENT,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    evaluation_id INT NOT NULL,
    area_competency_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_evaluation_area_competency (evaluation_id),
    KEY idx_eac_competency (area_competency_id),
    KEY idx_eac_school_year (school_id, academic_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Las calificaciones del modelo nuevo usan su propia FK lógica.
-- competencia_id permanece intacta para no alterar el modelo ponderado.
ALTER TABLE evaluation_grades
    ADD COLUMN IF NOT EXISTS area_competencia_id INT NULL DEFAULT NULL AFTER competencia_id,
    ADD INDEX IF NOT EXISTS idx_evaluation_grades_area_competency (area_competencia_id, evaluation_id, student_id);
