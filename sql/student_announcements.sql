-- Comunicados institucionales para estudiantes.
-- Ejecutar manualmente una sola vez por base de datos.

CREATE TABLE IF NOT EXISTS student_announcements (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    title VARCHAR(180) NOT NULL,
    content TEXT NOT NULL,
    audience_type VARCHAR(20) NOT NULL,
    audience_level VARCHAR(50) NULL,
    audience_grade VARCHAR(30) NULL,
    audience_section VARCHAR(30) NULL,
    audience_student_id INT NULL,
    recipient_count INT NOT NULL DEFAULT 0,
    push_sent_count INT NOT NULL DEFAULT 0,
    push_failed_count INT NOT NULL DEFAULT 0,
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_announcement_school_date (school_id, created_at),
    INDEX idx_announcement_student (school_id, audience_student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
