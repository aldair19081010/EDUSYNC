-- Control de accesos de estudiantes y política institucional de notas.
-- Ejecutar una sola vez por base de datos de EduSync.

ALTER TABLE student
    ADD COLUMN IF NOT EXISTS portal_password_hash VARCHAR(255) NULL AFTER id_no,
    ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL AFTER portal_password_hash,
    ADD COLUMN IF NOT EXISTS portal_last_login_at DATETIME NULL AFTER password_changed_at;

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


-- Ampliación de la política de notas: gracia, conceptos, excepciones y apertura temporal.
ALTER TABLE school_grade_access_policy
    ADD COLUMN IF NOT EXISTS grace_days INT UNSIGNED NOT NULL DEFAULT 0 AFTER debt_scope,
    ADD COLUMN IF NOT EXISTS grace_message VARCHAR(500) NOT NULL DEFAULT 'Tienes obligaciones vencidas, pero todavía estás dentro del periodo de gracia definido por la institución.' AFTER block_message,
    ADD COLUMN IF NOT EXISTS temporary_access_until DATETIME NULL AFTER grace_message,
    ADD COLUMN IF NOT EXISTS temporary_message VARCHAR(500) NOT NULL DEFAULT 'La institución ha habilitado temporalmente la consulta de calificaciones.' AFTER temporary_access_until,
    ADD COLUMN IF NOT EXISTS exception_message VARCHAR(500) NOT NULL DEFAULT 'Tu acceso a calificaciones está habilitado por una excepción autorizada por la institución.' AFTER temporary_message;

CREATE TABLE IF NOT EXISTS student_grade_access_exception (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id INT(11) NOT NULL,
    student_id INT(30) NOT NULL,
    reason VARCHAR(500) DEFAULT NULL,
    starts_at DATETIME NOT NULL,
    expires_at DATETIME NULL,
    created_by INT(30) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_grade_exception_school (school_id),
    KEY idx_grade_exception_student (student_id),
    KEY idx_grade_exception_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS grade_access_policy_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id INT(11) NOT NULL,
    actor_user_id INT(30) DEFAULT NULL,
    action VARCHAR(60) NOT NULL,
    details LONGTEXT DEFAULT NULL,
    ip_address VARCHAR(64) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_grade_policy_audit_school (school_id),
    KEY idx_grade_policy_audit_actor (actor_user_id),
    KEY idx_grade_policy_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
