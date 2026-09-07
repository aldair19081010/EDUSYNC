-- Conservación del nombre de conceptos en pagos históricos.
-- Ejecutar MANUALMENTE una sola vez después de payments_module_upgrade.sql.

CREATE TABLE IF NOT EXISTS payment_concept_snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    payment_operation_id BIGINT NOT NULL,
    debt_id INT NOT NULL,
    course_id INT NULL,
    concept_name VARCHAR(255) NOT NULL,
    level_name VARCHAR(50) NULL,
    academic_year_label VARCHAR(20) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_payment_concept_snapshot (payment_operation_id,debt_id),
    INDEX idx_payment_concept_school (school_id,created_at),
    INDEX idx_payment_concept_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Copiar los nombres que todavía existen.
INSERT INTO payment_concept_snapshots
    (school_id,payment_operation_id,debt_id,course_id,concept_name,level_name,academic_year_label,created_at)
SELECT po.school_id,po.id,ef.id,ef.course_id,c.course,c.level,ay.year,MIN(p.date_created)
FROM payment_operations po
INNER JOIN payments p ON p.operation_id=po.id
INNER JOIN student_ef_list ef ON ef.id=p.ef_id
INNER JOIN courses c ON c.id=ef.course_id
LEFT JOIN academic_year ay ON ay.id=c.academic_year_id
GROUP BY po.school_id,po.id,ef.id,ef.course_id,c.course,c.level,ay.year
ON DUPLICATE KEY UPDATE concept_name=payment_concept_snapshots.concept_name;

-- Reparación verificada del dato histórico perdido:
-- Colegio 1, antiguo concepto 26 = Matrícula.
INSERT INTO payment_concept_snapshots
    (school_id,payment_operation_id,debt_id,course_id,concept_name,level_name,academic_year_label,created_at)
SELECT po.school_id,po.id,ef.id,ef.course_id,'Matrícula',s.nivel,NULL,MIN(p.date_created)
FROM payment_operations po
INNER JOIN payments p ON p.operation_id=po.id
INNER JOIN student_ef_list ef ON ef.id=p.ef_id
INNER JOIN student s ON s.id=ef.student_id AND s.school_id=po.school_id
WHERE po.school_id=1 AND ef.course_id=26
GROUP BY po.school_id,po.id,ef.id,ef.course_id,s.nivel
ON DUPLICATE KEY UPDATE concept_name='Matrícula';

-- Compatibilidad con operaciones LEG cuyos pagos aún no tienen operation_id.
INSERT INTO payment_concept_snapshots
    (school_id,payment_operation_id,debt_id,course_id,concept_name,level_name,academic_year_label,created_at)
SELECT po.school_id,po.id,ef.id,ef.course_id,'Matrícula',s.nivel,NULL,MIN(p.date_created)
FROM payment_operations po
INNER JOIN student s ON s.id=po.student_id AND s.school_id=po.school_id
INNER JOIN student_ef_list ef ON ef.student_id=s.id AND ef.course_id=26
INNER JOIN payments p ON p.ef_id=ef.id AND p.operation_id IS NULL
WHERE po.school_id=1 AND po.receipt_series='LEG'
  AND CONVERT(po.receipt_full USING utf8mb4) COLLATE utf8mb4_general_ci
      = CONVERT(CONCAT('LEG-',po.school_id,'-',po.student_id,'-',p.receipt_no) USING utf8mb4) COLLATE utf8mb4_general_ci
GROUP BY po.school_id,po.id,ef.id,ef.course_id,s.nivel
ON DUPLICATE KEY UPDATE concept_name='Matrícula';
