-- EduSync v6 - Intervenciones vinculadas a un curso específico.
-- Ejecutar UNA VEZ si student_risk_interventions ya fue creada con la migración anterior.
-- Para instalaciones nuevas, también se actualizará predictive_interventions_upgrade.sql.

ALTER TABLE student_risk_interventions
    ADD COLUMN course_id INT NULL AFTER student_id,
    ADD COLUMN course_name VARCHAR(160) NULL AFTER course_id,
    ADD INDEX idx_risk_intervention_student_course (school_id,student_id,course_id,created_at);
