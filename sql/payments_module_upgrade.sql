-- Operaciones seguras, caja, correlativos y auditoría del módulo de pagos.
-- Puede ejecutarse nuevamente si una instalaciÃ³n anterior quedÃ³ incompleta.

CREATE TABLE IF NOT EXISTS payment_operations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    receipt_series VARCHAR(15) NOT NULL DEFAULT 'REC',
    receipt_number INT NOT NULL,
    receipt_full VARCHAR(50) NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    payment_date DATETIME NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Confirmado',
    remarks TEXT NULL,
    cash_session_id BIGINT NULL,
    created_by INT NULL,
    cancelled_at DATETIME NULL,
    cancelled_by INT NULL,
    cancellation_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_payment_receipt (school_id, receipt_full),
    INDEX idx_payment_operation_date (school_id, payment_date),
    INDEX idx_payment_operation_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS operation_id BIGINT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) NOT NULL DEFAULT 'Confirmado' AFTER payment_method_id,
    ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER payment_status,
    ADD INDEX IF NOT EXISTS idx_payments_operation (operation_id),
    ADD INDEX IF NOT EXISTS idx_payments_status (payment_status);

CREATE TABLE IF NOT EXISTS payment_operation_methods (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    operation_id BIGINT NOT NULL,
    payment_method_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reference_number VARCHAR(100) NULL,
    bank_name VARCHAR(100) NULL,
    operation_date DATE NULL,
    INDEX idx_payment_method_operation (operation_id),
    INDEX idx_payment_method_reference (reference_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_counters (
    school_id INT NOT NULL,
    series VARCHAR(15) NOT NULL,
    last_number INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (school_id, series)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cash_sessions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    user_id INT NOT NULL,
    opened_at DATETIME NOT NULL,
    opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    closed_at DATETIME NULL,
    closing_balance DECIMAL(12,2) NULL,
    expected_balance DECIMAL(12,2) NULL,
    difference_amount DECIMAL(12,2) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Abierta',
    notes TEXT NULL,
    INDEX idx_cash_session_school (school_id, status, opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    operation_id BIGINT NULL,
    payment_id INT NULL,
    user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    details LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment_audit_school (school_id, created_at),
    INDEX idx_payment_audit_operation (operation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Convertir pagos anteriores en operaciones agrupadas por colegio y número de recibo.
INSERT INTO payment_operations
    (school_id, student_id, receipt_series, receipt_number, receipt_full,
     total_amount, payment_date, status, remarks, created_at)
SELECT s.school_id, ef.student_id, 'LEG', MIN(p.id),
       CONCAT('LEG-', s.school_id, '-', ef.student_id, '-', p.receipt_no), SUM(p.amount),
       MIN(p.date_created), 'Confirmado', MAX(p.remarks), MIN(p.date_created)
FROM payments p
INNER JOIN student_ef_list ef ON ef.id = p.ef_id
INNER JOIN student s ON s.id = ef.student_id
WHERE p.operation_id IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM payment_operations existing
      WHERE existing.school_id = s.school_id
        AND existing.student_id = ef.student_id
        AND CONVERT(existing.receipt_full USING utf8mb4) COLLATE utf8mb4_general_ci
            = CONVERT(CONCAT('LEG-', s.school_id, '-', ef.student_id, '-', p.receipt_no) USING utf8mb4) COLLATE utf8mb4_general_ci
  )
GROUP BY s.school_id, ef.student_id, p.receipt_no;

UPDATE payments p
INNER JOIN student_ef_list ef ON ef.id = p.ef_id
INNER JOIN student s ON s.id = ef.student_id
INNER JOIN payment_operations po
    ON po.school_id = s.school_id
   AND po.student_id = ef.student_id
   AND CONVERT(po.receipt_full USING utf8mb4) COLLATE utf8mb4_general_ci
       = CONVERT(CONCAT('LEG-', s.school_id, '-', ef.student_id, '-', p.receipt_no) USING utf8mb4) COLLATE utf8mb4_general_ci
SET p.operation_id = po.id
WHERE p.operation_id IS NULL;

-- Recuperar los medios de pago históricos cuando existen desgloses.
INSERT INTO payment_operation_methods (operation_id, payment_method_id, amount)
SELECT p.operation_id, ps.payment_method_id, SUM(ps.amount)
FROM payment_split ps
INNER JOIN payments p ON p.id = ps.payment_id
WHERE p.operation_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM payment_operation_methods existing
      WHERE existing.operation_id = p.operation_id
        AND existing.payment_method_id = ps.payment_method_id
  )
GROUP BY p.operation_id, ps.payment_method_id;

-- Compatibilidad para pagos antiguos que solo guardaban payment_method_id.
INSERT INTO payment_operation_methods (operation_id, payment_method_id, amount)
SELECT p.operation_id, p.payment_method_id, SUM(p.amount)
FROM payments p
WHERE p.operation_id IS NOT NULL
  AND p.payment_method_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM payment_operation_methods pom
      WHERE pom.operation_id = p.operation_id
  )
GROUP BY p.operation_id, p.payment_method_id;

INSERT INTO payment_counters (school_id, series, last_number)
SELECT id, CONCAT('REC-', YEAR(CURDATE())), 0 FROM schools
ON DUPLICATE KEY UPDATE last_number = last_number;
