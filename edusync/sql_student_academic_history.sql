CREATE TABLE IF NOT EXISTS student_academic_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT(30) NOT NULL,
  school_id INT(11) NOT NULL,
  academic_year_id INT(11) DEFAULT NULL,
  nivel VARCHAR(20) NOT NULL,
  grado VARCHAR(10) NOT NULL,
  seccion VARCHAR(10) DEFAULT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Activo',
  change_type ENUM('initial','update','import','bulk') NOT NULL DEFAULT 'update',
  user_id INT(11) DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_student_history_student (student_id, created_at),
  KEY idx_student_history_school (school_id, academic_year_id),
  KEY idx_student_history_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
