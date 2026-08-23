CREATE TABLE IF NOT EXISTS teacher_employment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Activo',
    departure_reason VARCHAR(50) NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_teacher_employment (teacher_id, school_id),
    INDEX idx_teacher_employment_year (academic_year_id),
    INDEX idx_teacher_employment_status (school_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
