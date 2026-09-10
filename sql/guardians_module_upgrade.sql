-- Módulo de Apoderados de EduSync.
-- Ejecutar manualmente una sola vez por base de datos antes de usar la pantalla Apoderados.

-- Compatibilidad con instalaciones antiguas del módulo de usuarios.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS status ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo' AFTER teacher_id;

CREATE TABLE IF NOT EXISTS guardians (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id INT NOT NULL,
    user_id INT NULL,
    dni VARCHAR(20) NOT NULL,
    nombres VARCHAR(120) NOT NULL,
    apellido_paterno VARCHAR(80) NOT NULL,
    apellido_materno VARCHAR(80) NULL,
    telefono VARCHAR(30) NULL,
    email VARCHAR(160) NULL,
    status ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_guardian_school_dni (school_id, dni),
    UNIQUE KEY uq_guardian_school_user (school_id, user_id),
    KEY idx_guardian_school_status (school_id, status),
    KEY idx_guardian_name (school_id, apellido_paterno, apellido_materno, nombres)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS guardian_students (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id INT NOT NULL,
    guardian_id BIGINT UNSIGNED NOT NULL,
    student_id INT NOT NULL,
    parentesco VARCHAR(40) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    can_view_grades TINYINT(1) NOT NULL DEFAULT 1,
    can_view_attendance TINYINT(1) NOT NULL DEFAULT 1,
    can_view_payments TINYINT(1) NOT NULL DEFAULT 1,
    can_receive_communications TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_guardian_student (school_id, guardian_id, student_id),
    KEY idx_guardian_students_guardian (school_id, guardian_id, status),
    KEY idx_guardian_students_student (school_id, student_id, status),
    KEY idx_guardian_students_primary (school_id, student_id, is_primary, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS guardian_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id INT NOT NULL,
    guardian_id BIGINT UNSIGNED NOT NULL,
    actor_user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    details LONGTEXT NULL,
    ip_address VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_guardian_audit_guardian (school_id, guardian_id, created_at),
    KEY idx_guardian_audit_actor (school_id, actor_user_id),
    KEY idx_guardian_audit_date (school_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- No se migran automáticamente tutor1/tutor2 de student: esos campos históricos pueden
-- contener datos incompletos o repetidos. La vinculación se realiza desde el nuevo módulo
-- para evitar crear apoderados duplicados o asignaciones incorrectas.
