-- Seguimiento de intervenciones de Alerta Temprana Inteligente EduSync.
-- Ejecutar MANUALMENTE una sola vez en una base donde la tabla aún NO exista.
-- Si la tabla ya existe desde la versión anterior, usar sql/predictive_course_risk_upgrade.sql.

CREATE TABLE IF NOT EXISTS student_risk_interventions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NULL,
    student_id INT NOT NULL,
    course_id INT NULL,
    course_name VARCHAR(160) NULL,
    source_bimester TINYINT NULL,
    target_bimester TINYINT NULL,
    risk_probability DECIMAL(8,6) NULL,
    risk_level VARCHAR(20) NULL,
    intervention_type VARCHAR(60) NOT NULL,
    title VARCHAR(160) NOT NULL,
    notes VARCHAR(1000) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Pendiente',
    planned_date DATE NULL,
    followup_date DATE NULL,
    completed_at DATETIME NULL,
    outcome VARCHAR(30) NULL,
    post_risk_probability DECIMAL(8,6) NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_risk_intervention_school_status (school_id,status,created_at),
    INDEX idx_risk_intervention_student (school_id,student_id,created_at),
    INDEX idx_risk_intervention_student_course (school_id,student_id,course_id,created_at),
    INDEX idx_risk_intervention_followup (school_id,followup_date,status),
    INDEX idx_risk_intervention_year (school_id,academic_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS student_risk_intervention_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    intervention_id BIGINT NOT NULL,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    details LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_risk_intervention_log_record (intervention_id,created_at),
    INDEX idx_risk_intervention_log_school (school_id,created_at),
    INDEX idx_risk_intervention_log_student (school_id,student_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
