-- Historial por celda para el Libro de notas.
-- Ejecutar manualmente una sola vez después de grades_module_upgrade.sql.

CREATE TABLE IF NOT EXISTS evaluation_grade_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    evaluation_id INT NOT NULL,
    student_id INT NOT NULL,
    competency_id INT NOT NULL,
    previous_grade VARCHAR(10) NULL,
    new_grade VARCHAR(10) NULL,
    changed_by INT NULL,
    source VARCHAR(30) NOT NULL DEFAULT 'Libro de notas',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_grade_history_cell (evaluation_id, student_id, competency_id, created_at),
    INDEX idx_grade_history_school (school_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
