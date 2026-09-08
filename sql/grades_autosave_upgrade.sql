-- Autoguardado unificado de notas en EduSync
-- Ejecutar una sola vez por base de datos.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS grades_autosave TINYINT(1) NOT NULL DEFAULT 0;

UPDATE users
SET grades_autosave = 0
WHERE grades_autosave IS NULL;
