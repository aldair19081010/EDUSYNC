-- Cierre bimestral de notas por asignación docente.
-- Ejecutar MANUALMENTE una sola vez en cada base de datos.

CREATE TABLE IF NOT EXISTS grade_period_closures (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    teacher_course_id INT NOT NULL,
    teacher_id INT NOT NULL,
    bimester TINYINT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Abierto',
    closed_by INT NULL,
    closed_at DATETIME NULL,
    closure_snapshot LONGTEXT NULL,
    closure_version INT NOT NULL DEFAULT 0,
    reopened_by INT NULL,
    reopened_at DATETIME NULL,
    reopen_reason VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_grade_period_closure (school_id, teacher_course_id, bimester),
    INDEX idx_grade_period_status (school_id, academic_year_id, bimester, status),
    INDEX idx_grade_period_teacher (school_id, teacher_id, academic_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS grade_reopen_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    closure_id BIGINT NOT NULL,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    teacher_course_id INT NOT NULL,
    teacher_id INT NOT NULL,
    bimester TINYINT NOT NULL,
    requested_by INT NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Pendiente',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_notes VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_grade_reopen_school (school_id, status, created_at),
    INDEX idx_grade_reopen_closure (closure_id, status),
    INDEX idx_grade_reopen_teacher (school_id, teacher_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS grade_period_audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    teacher_course_id INT NOT NULL,
    teacher_id INT NULL,
    bimester TINYINT NOT NULL,
    user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    details LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_grade_period_audit (school_id, academic_year_id, bimester, created_at),
    INDEX idx_grade_period_audit_assignment (teacher_course_id, bimester, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
