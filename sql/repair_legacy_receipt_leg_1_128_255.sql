-- Reparación puntual del nombre perdido en el recibo histórico LEG-1-128-255.
-- Ejecutar MANUALMENTE después de payment_concept_snapshots_upgrade.sql.
-- No modifica montos, pagos, descuentos ni el número del recibo.

INSERT INTO payment_concept_snapshots
    (school_id, payment_operation_id, debt_id, course_id, concept_name,
     level_name, academic_year_label, created_at)
SELECT
    po.school_id,
    po.id,
    ef.id,
    ef.course_id,
    'Matrícula',
    s.nivel,
    NULL,
    COALESCE(MIN(p.date_created), po.payment_date, CURRENT_TIMESTAMP)
FROM payment_operations po
INNER JOIN student s
    ON s.id = po.student_id
   AND s.school_id = po.school_id
INNER JOIN student_ef_list ef
    ON ef.student_id = po.student_id
   AND ef.course_id = 26
INNER JOIN payments p
    ON p.ef_id = ef.id
   AND (
        p.operation_id = po.id
        OR (
            p.operation_id IS NULL
            AND CONVERT(po.receipt_full USING utf8mb4) COLLATE utf8mb4_general_ci
                = CONVERT(CONCAT('LEG-', po.school_id, '-', po.student_id, '-', p.receipt_no) USING utf8mb4) COLLATE utf8mb4_general_ci
        )
   )
WHERE po.school_id = 1
  AND po.student_id = 128
  AND CONVERT(po.receipt_full USING utf8mb4) COLLATE utf8mb4_general_ci = 'LEG-1-128-255'
GROUP BY po.school_id, po.id, ef.id, ef.course_id, s.nivel, po.payment_date
ON DUPLICATE KEY UPDATE
    concept_name = 'Matrícula',
    level_name = VALUES(level_name);

-- Verificación: debe devolver concept_name = Matrícula.
SELECT
    po.receipt_full,
    pcs.debt_id,
    pcs.course_id,
    pcs.concept_name,
    pcs.level_name
FROM payment_operations po
INNER JOIN payment_concept_snapshots pcs
    ON pcs.payment_operation_id = po.id
WHERE po.school_id = 1
  AND CONVERT(po.receipt_full USING utf8mb4) COLLATE utf8mb4_general_ci = 'LEG-1-128-255';
