CREATE TABLE IF NOT EXISTS student_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT(30) DEFAULT NULL,
  school_id INT(11) NOT NULL,
  user_id INT(11) DEFAULT NULL,
  action ENUM('create','update','delete','import') NOT NULL,
  details JSON DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_student_audit_student (student_id),
  KEY idx_student_audit_school_date (school_id, created_at),
  KEY idx_student_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
