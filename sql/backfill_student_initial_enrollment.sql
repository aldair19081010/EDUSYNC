-- Reconstrucción de la matrícula inicial a partir de student.date_created.
-- Ejecutar manualmente una sola vez y revisar primero los SELECT de diagnóstico.
-- Es repetible: no crea una segunda matrícula para el mismo estudiante y año.

CREATE TABLE IF NOT EXISTS student_academic_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id INT(30) NOT NULL,
    school_id INT(11) NOT NULL,
    academic_year_id INT(11) DEFAULT NULL,
    nivel VARCHAR(20) NOT NULL,
    grado VARCHAR(10) NOT NULL,
    seccion VARCHAR(10) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Activo',
    change_type ENUM('initial','update','import','bulk') NOT NULL DEFAULT 'update',
    user_id INT(11) DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_student_history_student (student_id, created_at),
    KEY idx_student_history_school (school_id, academic_year_id),
    KEY idx_student_history_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TEMPORARY TABLE IF EXISTS tmp_student_initial_enrollment;
CREATE TEMPORARY TABLE tmp_student_initial_enrollment (
    student_id INT NOT NULL PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    registered_at DATETIME NOT NULL,
    match_method VARCHAR(20) NOT NULL
) ENGINE=InnoDB;

-- Prioridad 1: año cuyas fechas contienen la fecha de registro.
-- Prioridad 2: coincidencia entre YEAR(date_created) y academic_year.year.
INSERT INTO tmp_student_initial_enrollment
    (student_id, school_id, academic_year_id, registered_at, match_method)
SELECT
    s.id,
    s.school_id,
    ay.id,
    s.date_created,
    CASE
        WHEN DATE(s.date_created) BETWEEN ay.start_date AND ay.end_date THEN 'Rango de fechas'
        ELSE 'Año calendario'
    END
FROM student s
INNER JOIN academic_year ay
    ON ay.id = (
        SELECT ay2.id
        FROM academic_year ay2
        WHERE ay2.school_id = s.school_id
          AND (
              DATE(s.date_created) BETWEEN ay2.start_date AND ay2.end_date
              OR CAST(ay2.year AS UNSIGNED) = YEAR(s.date_created)
          )
        ORDER BY
            CASE WHEN DATE(s.date_created) BETWEEN ay2.start_date AND ay2.end_date THEN 0 ELSE 1 END,
            ay2.start_date DESC,
            ay2.id DESC
        LIMIT 1
    );

-- REVISIÓN 1: estudiantes y año que recibirán como matrícula inicial.
SELECT
    s.id,
    s.id_no,
    s.name,
    s.date_created,
    ay.year AS proposed_academic_year,
    m.match_method,
    s.nivel,
    s.grado,
    s.seccion
FROM tmp_student_initial_enrollment m
INNER JOIN student s ON s.id = m.student_id AND s.school_id = m.school_id
INNER JOIN academic_year ay ON ay.id = m.academic_year_id
WHERE NOT EXISTS (
    SELECT 1
    FROM student_academic_history sah
    WHERE sah.student_id = m.student_id
      AND sah.school_id = m.school_id
      AND sah.academic_year_id = m.academic_year_id
)
ORDER BY s.school_id, s.date_created, s.name;

-- REVISIÓN 2: estudiantes sin ningún año compatible. Estos no se modifican.
SELECT s.id, s.id_no, s.name, s.school_id, s.date_created
FROM student s
LEFT JOIN tmp_student_initial_enrollment m ON m.student_id = s.id
WHERE m.student_id IS NULL
ORDER BY s.school_id, s.date_created, s.name;

START TRANSACTION;

-- Se registra una matrícula inicial inferida. Nivel, grado y sección se toman
-- del registro disponible actualmente; la nota deja constancia de esa limitación.
INSERT INTO student_academic_history
    (student_id, school_id, academic_year_id, nivel, grado, seccion,
     status, change_type, user_id, notes, created_at)
SELECT
    s.id,
    s.school_id,
    m.academic_year_id,
    s.nivel,
    s.grado,
    NULLIF(TRIM(s.seccion), ''),
    'Activo',
    'import',
    NULL,
    CONCAT('Matrícula inicial inferida por fecha de registro mediante ', m.match_method, '.'),
    s.date_created
FROM tmp_student_initial_enrollment m
INNER JOIN student s ON s.id = m.student_id AND s.school_id = m.school_id
WHERE NOT EXISTS (
    SELECT 1
    FROM student_academic_history sah
    WHERE sah.student_id = m.student_id
      AND sah.school_id = m.school_id
      AND sah.academic_year_id = m.academic_year_id
);

SET @inserted_initial_enrollments := ROW_COUNT();

COMMIT;

SELECT
    @inserted_initial_enrollments AS history_rows_inserted;

-- CONTROL FINAL: cantidad de matrículas inferidas por colegio y año.
SELECT
    sah.school_id,
    ay.year,
    COUNT(*) AS inferred_enrollments
FROM student_academic_history sah
INNER JOIN academic_year ay ON ay.id = sah.academic_year_id
WHERE sah.change_type = 'import'
  AND sah.notes LIKE 'Matrícula inicial inferida por fecha de registro%'
GROUP BY sah.school_id, ay.id, ay.year
ORDER BY sah.school_id, ay.year;

DROP TEMPORARY TABLE IF EXISTS tmp_student_initial_enrollment;
