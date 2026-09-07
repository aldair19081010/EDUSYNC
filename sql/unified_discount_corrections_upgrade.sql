-- Instantáneas inmutables y corrección unificada de descuentos.
-- Ejecutar MANUALMENTE una sola vez después de:
--   payment_discounts_upgrade.sql
--   payment_corrections_upgrade.sql
--   discounts_module_upgrade.sql

CREATE TABLE IF NOT EXISTS payment_discount_snapshots (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    payment_operation_id BIGINT NOT NULL,
    debt_id INT NOT NULL,
    source_type VARCHAR(20) NOT NULL,
    source_id BIGINT NULL,
    original_amount DECIMAL(12,2) NOT NULL,
    previous_effective_amount DECIMAL(12,2) NOT NULL,
    discount_amount DECIMAL(12,2) NOT NULL,
    final_effective_amount DECIMAL(12,2) NOT NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_operation_debt_discount (payment_operation_id, debt_id),
    INDEX idx_discount_snapshot_source (source_type, source_id),
    INDEX idx_discount_snapshot_school (school_id, created_at),
    INDEX idx_discount_snapshot_debt (debt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Instantáneas de descuentos concedidos durante el cobro.
INSERT INTO payment_discount_snapshots
    (school_id,payment_operation_id,debt_id,source_type,source_id,original_amount,
     previous_effective_amount,discount_amount,final_effective_amount,reason,created_at)
SELECT dd.school_id,dd.payment_operation_id,dd.debt_id,'Pago',dd.id,ef.total_fee,
       dd.previous_effective_amount,dd.discount_amount,dd.final_effective_amount,dd.reason,dd.created_at
FROM debt_discounts dd
INNER JOIN student_ef_list ef ON ef.id=dd.debt_id
ON DUPLICATE KEY UPDATE source_id=payment_discount_snapshots.source_id;

-- Si una misma operación combinó un beneficio administrativo con otro concedido
-- durante el cobro, conservar el vínculo administrativo para su corrección.
UPDATE payment_discount_snapshots pds
INNER JOIN discount_benefits db ON db.debt_id=pds.debt_id AND db.school_id=pds.school_id
INNER JOIN payment_operations po ON po.id=pds.payment_operation_id
SET pds.source_type='Combinado',pds.source_id=db.id,
    pds.original_amount=db.original_amount,
    pds.discount_amount=GREATEST(db.original_amount-pds.final_effective_amount,0),
    pds.reason=CONCAT_WS(' | ',NULLIF(db.reason,''),NULLIF(pds.reason,''))
WHERE pds.source_type='Pago'
  AND po.payment_date>=db.created_at
  AND (db.revoked_at IS NULL OR po.payment_date<=db.revoked_at);

-- Recuperar descuentos administrativos históricos que ya afectaron un recibo.
-- Se vincula cada operación emitida durante la vigencia conocida del beneficio.
INSERT INTO payment_discount_snapshots
    (school_id,payment_operation_id,debt_id,source_type,source_id,original_amount,
     previous_effective_amount,discount_amount,final_effective_amount,reason,created_at)
SELECT db.school_id,p.operation_id,db.debt_id,'Administración',db.id,db.original_amount,
       db.previous_effective_amount,db.discount_amount,db.final_effective_amount,db.reason,
       MIN(p.date_created)
FROM discount_benefits db
INNER JOIN payments p ON p.ef_id=db.debt_id AND p.operation_id IS NOT NULL
INNER JOIN payment_operations po ON po.id=p.operation_id AND po.school_id=db.school_id
WHERE p.date_created>=db.created_at
  AND (db.revoked_at IS NULL OR p.date_created<=db.revoked_at)
GROUP BY db.id,p.operation_id,db.debt_id,db.school_id,db.original_amount,
         db.previous_effective_amount,db.discount_amount,db.final_effective_amount,db.reason
ON DUPLICATE KEY UPDATE source_id=payment_discount_snapshots.source_id;
