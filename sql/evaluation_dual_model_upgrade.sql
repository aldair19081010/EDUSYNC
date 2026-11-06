-- EduSync: dos modelos de evaluación sin alterar la estructura histórica ponderada.
-- Ejecutar una sola vez en cada base de datos (local y producción).
-- Las tablas general_course_competencies, evaluation_competencias y evaluation_grades
-- permanecen intactas y siguen atendiendo el modelo ponderado histórico.

ALTER TABLE academic_courses
    ADD COLUMN IF NOT EXISTS evaluation_mode VARCHAR(20) NOT NULL DEFAULT 'weighted' AFTER level;

CREATE TABLE IF NOT EXISTS area_competencies (
    id INT NOT NULL AUTO_INCREMENT,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    area_id INT NOT NULL,
    level VARCHAR(30) NOT NULL,
    code VARCHAR(50) NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_area_competency_scope (school_id, academic_year_id, area_id, level, name),
    KEY idx_area_competency_area (school_id, academic_year_id, area_id, level, is_active),
    KEY idx_area_competency_order (area_id, level, display_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_area_competencies (
    id BIGINT NOT NULL AUTO_INCREMENT,
    evaluation_id INT NOT NULL,
    area_competency_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_area_competency_evaluation (evaluation_id),
    KEY idx_area_competency_link (area_competency_id, evaluation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_area_grades (
    id BIGINT NOT NULL AUTO_INCREMENT,
    evaluation_id INT NOT NULL,
    area_competency_id INT NOT NULL,
    student_id INT NOT NULL,
    grade VARCHAR(10) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_area_grade_evaluation_student (evaluation_id, student_id),
    KEY idx_area_grade_student (student_id, area_competency_id, evaluation_id),
    KEY idx_area_grade_competency (area_competency_id, evaluation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluation_area_grade_history (
    id BIGINT NOT NULL AUTO_INCREMENT,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    evaluation_id INT NOT NULL,
    student_id INT NOT NULL,
    area_competency_id INT NOT NULL,
    previous_grade VARCHAR(10) NULL,
    new_grade VARCHAR(10) NULL,
    changed_by INT NULL,
    source VARCHAR(30) NOT NULL DEFAULT 'Libro de notas',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_area_grade_history_cell (evaluation_id, student_id, area_competency_id, created_at),
    KEY idx_area_grade_history_school (school_id, academic_year_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
