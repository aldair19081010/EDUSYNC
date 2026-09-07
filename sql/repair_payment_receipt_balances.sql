-- Reparar saldos históricos cuando un descuento de una boleta anterior
-- debe continuar vigente en las boletas posteriores de la misma deuda.
-- Ejecutar MANUALMENTE después de payment_receipt_balance_snapshots_upgrade.sql.

UPDATE payment_concept_snapshots pcs
INNER JOIN payment_operations po
    ON po.id=pcs.payment_operation_id
   AND po.school_id=pcs.school_id
INNER JOIN student_ef_list ef ON ef.id=pcs.debt_id
SET pcs.original_amount_snapshot=ef.total_fee,
    pcs.effective_amount_snapshot=COALESCE(
        (SELECT pds.final_effective_amount
         FROM payment_discount_snapshots pds
         INNER JOIN payment_operations pod ON pod.id=pds.payment_operation_id
         WHERE pds.debt_id=pcs.debt_id
           AND pds.school_id=pcs.school_id
           AND (pod.payment_date<po.payment_date OR (pod.payment_date=po.payment_date AND pod.id<=po.id))
         ORDER BY pod.payment_date DESC,pod.id DESC,pds.id DESC
         LIMIT 1),
        ef.total_fee
    ),
    pcs.amount_applied=COALESCE((
        SELECT SUM(p.amount)
        FROM payments p
        WHERE p.operation_id=pcs.payment_operation_id
          AND p.ef_id=pcs.debt_id
    ),pcs.amount_applied,0),
    pcs.paid_before=COALESCE((
        SELECT SUM(p2.amount)
        FROM payments p2
        INNER JOIN payment_operations po2 ON po2.id=p2.operation_id
        WHERE p2.ef_id=pcs.debt_id
          AND p2.payment_status='Confirmado'
          AND po2.status='Confirmado'
          AND (po2.payment_date<po.payment_date OR (po2.payment_date=po.payment_date AND po2.id<po.id))
    ),0);

UPDATE payment_concept_snapshots
SET balance_after=GREATEST(effective_amount_snapshot-paid_before-amount_applied,0)
WHERE effective_amount_snapshot IS NOT NULL;

-- Verificar las dos boletas del ejemplo. La segunda debe terminar con saldo 0.00.
SELECT po.receipt_full,pcs.original_amount_snapshot,pcs.effective_amount_snapshot,
       pcs.paid_before,pcs.amount_applied,pcs.balance_after
FROM payment_concept_snapshots pcs
INNER JOIN payment_operations po ON po.id=pcs.payment_operation_id
WHERE po.receipt_full IN ('REC-2026-000019','REC-2026-000020')
ORDER BY po.payment_date,po.id;
