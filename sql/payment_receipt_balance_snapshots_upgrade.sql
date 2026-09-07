-- Saldos inmutables por cada boleta emitida.
-- Ejecutar MANUALMENTE después de payment_concept_snapshots_upgrade.sql.

ALTER TABLE payment_concept_snapshots
    ADD COLUMN IF NOT EXISTS original_amount_snapshot DECIMAL(12,2) NULL AFTER academic_year_label,
    ADD COLUMN IF NOT EXISTS effective_amount_snapshot DECIMAL(12,2) NULL AFTER original_amount_snapshot,
    ADD COLUMN IF NOT EXISTS paid_before DECIMAL(12,2) NULL AFTER effective_amount_snapshot,
    ADD COLUMN IF NOT EXISTS amount_applied DECIMAL(12,2) NULL AFTER paid_before,
    ADD COLUMN IF NOT EXISTS balance_after DECIMAL(12,2) NULL AFTER amount_applied;

-- Reconstruir las operaciones enlazadas usando su orden de emisión.
UPDATE payment_concept_snapshots pcs
INNER JOIN payment_operations po ON po.id=pcs.payment_operation_id AND po.school_id=pcs.school_id
INNER JOIN student_ef_list ef ON ef.id=pcs.debt_id
SET pcs.original_amount_snapshot=ef.total_fee,
    pcs.effective_amount_snapshot=COALESCE(
        (SELECT pds.final_effective_amount
         FROM payment_discount_snapshots pds
         INNER JOIN payment_operations pod ON pod.id=pds.payment_operation_id
         WHERE pds.debt_id=pcs.debt_id
           AND pds.school_id=pcs.school_id
           AND (pod.payment_date<po.payment_date OR (pod.payment_date=po.payment_date AND pod.id<=po.id))
         ORDER BY pod.payment_date DESC,pod.id DESC,pds.id DESC LIMIT 1),
        ef.total_fee
    ),
    pcs.amount_applied=COALESCE((
        SELECT SUM(p.amount) FROM payments p
        WHERE p.operation_id=pcs.payment_operation_id AND p.ef_id=pcs.debt_id
    ),0),
    pcs.paid_before=COALESCE((
        SELECT SUM(p2.amount)
        FROM payments p2
        INNER JOIN payment_operations po2 ON po2.id=p2.operation_id
        WHERE p2.ef_id=pcs.debt_id
          AND p2.payment_status='Confirmado'
          AND po2.status='Confirmado'
          AND (po2.payment_date<po.payment_date OR (po2.payment_date=po.payment_date AND po2.id<po.id))
    ),0)
;

-- Recuperar importes de operaciones LEG aunque payments.operation_id haya quedado NULL.
UPDATE payment_concept_snapshots pcs
INNER JOIN payment_operations po ON po.id=pcs.payment_operation_id AND po.school_id=pcs.school_id
SET pcs.amount_applied=COALESCE((
        SELECT SUM(pl.amount)
        FROM payments pl
        WHERE pl.ef_id=pcs.debt_id
          AND (
              pl.operation_id=po.id
              OR (
                  pl.operation_id IS NULL
                  AND CONVERT(po.receipt_full USING utf8mb4) COLLATE utf8mb4_general_ci
                      = CONVERT(CONCAT('LEG-',po.school_id,'-',po.student_id,'-',pl.receipt_no) USING utf8mb4) COLLATE utf8mb4_general_ci
              )
          )
    ),0),
    pcs.paid_before=COALESCE((
        SELECT SUM(pp.amount)
        FROM payments pp
        WHERE pp.ef_id=pcs.debt_id
          AND COALESCE(pp.payment_status,'Confirmado')='Confirmado'
          AND pp.date_created<po.payment_date
    ),0)
WHERE po.receipt_series='LEG';

UPDATE payment_concept_snapshots
SET balance_after=GREATEST(effective_amount_snapshot-paid_before-amount_applied,0)
WHERE effective_amount_snapshot IS NOT NULL;

-- Verificación: estos campos deben quedar completos para los recibos migrados.
SELECT COUNT(*) AS snapshots_sin_saldo
FROM payment_concept_snapshots
WHERE balance_after IS NULL;
