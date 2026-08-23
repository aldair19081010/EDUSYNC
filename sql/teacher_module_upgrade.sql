ALTER TABLE teacher
    ADD COLUMN IF NOT EXISTS birth_date DATE NULL AFTER name;

ALTER TABLE teacher_employment_history
    ADD COLUMN IF NOT EXISTS departure_reason VARCHAR(50) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER departure_reason;

CREATE TABLE IF NOT EXISTS teacher_audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NULL,
    school_id INT NOT NULL,
    user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_teacher_audit_teacher (teacher_id, school_id),
    INDEX idx_teacher_audit_action (school_id, action),
    INDEX idx_teacher_audit_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
