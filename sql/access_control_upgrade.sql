-- Control de accesos de estudiantes y política institucional de notas.
-- Ejecutar una sola vez por base de datos de EduSync.

ALTER TABLE student
    ADD COLUMN IF NOT EXISTS portal_password_hash VARCHAR(255) NULL AFTER id_no,
    ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL AFTER portal_password_hash;

CREATE TABLE IF NOT EXISTS student_access_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id INT(11) NOT NULL,
    student_id INT(30) NOT NULL,
    student_dni VARCHAR(50) DEFAULT NULL,
    actor_user_id INT(30) DEFAULT NULL,
    action VARCHAR(40) NOT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(64) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_student_access_school (school_id),
    KEY idx_student_access_student (student_id),
    KEY idx_student_access_actor (actor_user_id),
    KEY idx_student_access_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS school_grade_access_policy (
    school_id INT(11) NOT NULL,
    block_grades_by_debt TINYINT(1) NOT NULL DEFAULT 1,
    minimum_debt_concepts INT UNSIGNED NOT NULL DEFAULT 2,
    debt_scope ENUM('overdue','pending') NOT NULL DEFAULT 'overdue',
    block_message VARCHAR(500) NOT NULL DEFAULT 'Las calificaciones están temporalmente restringidas por obligaciones de pago vencidas. Comunícate con la institución para regularizar tu situación.',
    updated_by INT(30) DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO school_grade_access_policy (
    school_id,
    block_grades_by_debt,
    minimum_debt_concepts,
    debt_scope,
    block_message
)
SELECT
    s.id,
    1,
    2,
    'overdue',
    'Las calificaciones están temporalmente restringidas por obligaciones de pago vencidas. Comunícate con la institución para regularizar tu situación.'
FROM schools s
LEFT JOIN school_grade_access_policy p ON p.school_id = s.id
WHERE p.school_id IS NULL;
