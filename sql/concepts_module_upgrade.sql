-- Seguridad, archivado, grados normalizados y auditoría para Conceptos de Pago.
-- Ejecutar manualmente una vez en cada base de datos.

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS concept_status VARCHAR(20) NOT NULL DEFAULT 'Activo' AFTER total_amount,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER date_created,
    ADD INDEX IF NOT EXISTS idx_courses_year_status (academic_year_id, concept_status);

CREATE TABLE IF NOT EXISTS course_grades (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    grade VARCHAR(30) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_course_grade (course_id, grade),
    INDEX idx_course_grades_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS concept_audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NULL,
    course_id INT NULL,
    user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    details LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_concept_audit_school (school_id, created_at),
    INDEX idx_concept_audit_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Normalizar los grados guardados anteriormente en courses.grades.
INSERT IGNORE INTO course_grades (course_id, grade)
SELECT c.id,
       TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(c.grades, ',', numbers.n), ',', -1)) AS grade
FROM courses c
JOIN (
    SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
    UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8
) numbers ON numbers.n <= 1 + LENGTH(c.grades) - LENGTH(REPLACE(c.grades, ',', ''))
WHERE c.grades IS NOT NULL
  AND TRIM(c.grades) <> ''
  AND TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(c.grades, ',', numbers.n), ',', -1)) <> '';

-- Normalizar Inicial al mismo formato usado actualmente por la ficha de estudiantes.
INSERT IGNORE INTO course_grades (course_id, grade)
SELECT cg.course_id, CONCAT(SUBSTRING_INDEX(cg.grade, ' ', 1), '°')
FROM course_grades cg
JOIN courses c ON c.id = cg.course_id
WHERE c.level = 'Inicial' AND cg.grade IN ('3 años', '4 años', '5 años');

DELETE cg FROM course_grades cg
JOIN courses c ON c.id = cg.course_id
WHERE c.level = 'Inicial' AND cg.grade IN ('3 años', '4 años', '5 años');

-- Mantener también la columna antigua porque otros módulos todavía la consultan.
UPDATE courses c
SET c.grades = (
    SELECT GROUP_CONCAT(cg.grade ORDER BY cg.id SEPARATOR ',')
    FROM course_grades cg
    WHERE cg.course_id = c.id
)
WHERE EXISTS (SELECT 1 FROM course_grades cg WHERE cg.course_id = c.id);
