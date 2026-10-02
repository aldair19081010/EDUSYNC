-- Campañas manuales de cobranza para estudiantes.
-- Ejecutar manualmente una sola vez por base de datos.

CREATE TABLE IF NOT EXISTS student_collection_campaigns (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    audience_type VARCHAR(20) NOT NULL,
    audience_level VARCHAR(50) NULL,
    audience_grade VARCHAR(30) NULL,
    audience_section VARCHAR(30) NULL,
    audience_student_id INT NULL,
    include_overdue TINYINT(1) NOT NULL DEFAULT 1,
    include_partial TINYINT(1) NOT NULL DEFAULT 1,
    include_upcoming TINYINT(1) NOT NULL DEFAULT 0,
    recipient_count INT NOT NULL DEFAULT 0,
    debt_count INT NOT NULL DEFAULT 0,
    total_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    students_with_devices INT NOT NULL DEFAULT 0,
    push_sent_count INT NOT NULL DEFAULT 0,
    push_failed_count INT NOT NULL DEFAULT 0,
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_collection_campaign_school_date (school_id, created_at),
    INDEX idx_collection_campaign_student (school_id, audience_student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS student_collection_recipients (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT NOT NULL,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    debt_count INT NOT NULL DEFAULT 0,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    debt_ids_json TEXT NULL,
    concepts_json TEXT NULL,
    notification_event_id BIGINT NULL,
    active_device_count INT NOT NULL DEFAULT 0,
    sent_count INT NOT NULL DEFAULT 0,
    failed_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_collection_campaign_student (campaign_id, student_id),
    INDEX idx_collection_recipient_school_student (school_id, student_id),
    INDEX idx_collection_recipient_event (notification_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
