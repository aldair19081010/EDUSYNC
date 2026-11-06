-- EduSync: modelo dual de evaluación y competencias curriculares por área.
-- Ejecutar una sola vez en la base de datos, después de respaldarla.
-- Los cursos existentes conservarán el modelo ponderado.

SET @edusync_schema = DATABASE();

SET @edusync_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=@edusync_schema AND TABLE_NAME='academic_courses'
       AND COLUMN_NAME='evaluation_model')=0,
    'ALTER TABLE academic_courses ADD COLUMN evaluation_model VARCHAR(20) NOT NULL DEFAULT ''weighted'' AFTER course_type',
    'SELECT 1'
);
PREPARE edusync_stmt FROM @edusync_ddl;
EXECUTE edusync_stmt;
DEALLOCATE PREPARE edusync_stmt;

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

CREATE TABLE IF NOT EXISTS evaluation_area_competencies (
    id BIGINT NOT NULL AUTO_INCREMENT,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    evaluation_id INT NOT NULL,
    area_competencia_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_evaluation_area_competency (evaluation_id),
    KEY idx_eac_competency (area_competencia_id),
    KEY idx_eac_school_year (school_id, academic_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @edusync_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=@edusync_schema AND TABLE_NAME='evaluation_grades'
       AND COLUMN_NAME='area_competencia_id')=0,
    'ALTER TABLE evaluation_grades ADD COLUMN area_competencia_id INT NULL DEFAULT NULL AFTER competencia_id',
    'SELECT 1'
);
PREPARE edusync_stmt FROM @edusync_ddl;
EXECUTE edusync_stmt;
DEALLOCATE PREPARE edusync_stmt;

SET @edusync_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=@edusync_schema AND TABLE_NAME='evaluation_grades'
       AND INDEX_NAME='idx_evaluation_grades_area_competency')=0,
    'ALTER TABLE evaluation_grades ADD INDEX idx_evaluation_grades_area_competency (area_competencia_id, evaluation_id, student_id)',
    'SELECT 1'
);
PREPARE edusync_stmt FROM @edusync_ddl;
EXECUTE edusync_stmt;
DEALLOCATE PREPARE edusync_stmt;

-- No se migran ni modifican los registros de competencia_id existentes.
-- Las evaluaciones y notas del nuevo modelo usarán las columnas/relaciones nuevas.
