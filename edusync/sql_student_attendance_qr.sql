-- EduSync: credenciales QR de asistencia sin DNI ni nombre en el código.
-- Aplicar UNA vez antes de activar la emisión de carnés QR.
-- El servidor guarda exclusivamente SHA-256 del secreto aleatorio.
CREATE TABLE IF NOT EXISTS student_attendance_qr (
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    issued_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (school_id, student_id),
    UNIQUE KEY uq_student_attendance_qr_hash (token_hash),
    KEY idx_student_attendance_qr_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rotar el QR desde Administración reemplaza token_hash del estudiante.
-- Sin borrar notas, estudiantes o marcaciones previas.
