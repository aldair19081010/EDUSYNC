-- Contraseña personalizada para acceso de estudiantes.
-- Ejecutar una sola vez en cada base de datos antes de publicar el endpoint.

ALTER TABLE student
    ADD COLUMN IF NOT EXISTS portal_password_hash VARCHAR(255) NULL AFTER id_no,
    ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL AFTER portal_password_hash;
