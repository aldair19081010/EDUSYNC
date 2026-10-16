-- Comunicados institucionales dirigidos a docentes.
-- Ejecutar una vez en la BD local/de pruebas. No altera los comunicados estudiantiles.
CREATE TABLE IF NOT EXISTS teacher_announcements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 school_id INT NOT NULL,
 title VARCHAR(180) NOT NULL,
 content TEXT NOT NULL,
 audience_type VARCHAR(20) NOT NULL,
 recipient_count INT NOT NULL DEFAULT 0,
 request_key CHAR(32) NOT NULL,
 created_by INT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_teacher_ann_school (school_id,id),
 UNIQUE KEY uniq_teacher_announcement_request (school_id,request_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS teacher_announcement_recipients (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 school_id INT NOT NULL,
 announcement_id BIGINT UNSIGNED NOT NULL,
 recipient_user_id INT NOT NULL,
 notification_event_id BIGINT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_teacher_announcement_user (school_id,announcement_id,recipient_user_id),
 KEY idx_teacher_announcement_event (school_id,notification_event_id),
 KEY idx_teacher_announcement_user (school_id,recipient_user_id,announcement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
