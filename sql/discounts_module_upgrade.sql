-- Modelo unificado de descuentos, becas y exoneraciones.
-- Ejecutar MANUALMENTE una sola vez, después de fees_module_upgrade.sql.

CREATE TABLE IF NOT EXISTS discount_benefits (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NULL,
    student_id INT NOT NULL,
    debt_id INT NOT NULL,
    course_id INT NULL,
    benefit_type VARCHAR(30) NOT NULL DEFAULT 'Descuento',
    calculation_type VARCHAR(20) NOT NULL DEFAULT 'Monto final',
    value DECIMAL(12,2) NOT NULL,
    original_amount DECIMAL(12,2) NOT NULL,
    paid_before DECIMAL(12,2) NOT NULL DEFAULT 0,
    previous_effective_amount DECIMAL(12,2) NOT NULL,
    final_effective_amount DECIMAL(12,2) NOT NULL,
    discount_amount DECIMAL(12,2) NOT NULL,
    scope_type VARCHAR(20) NOT NULL DEFAULT 'Deuda',
    valid_from DATE NULL,
    valid_until DATE NULL,
    reason VARCHAR(255) NOT NULL,
    notes TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Aplicado',
    created_by INT NULL,
    revoked_at DATETIME NULL,
    revoked_by INT NULL,
    revocation_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_discount_school_status (school_id, status, created_at),
    INDEX idx_discount_debt (debt_id, status),
    INDEX idx_discount_student (student_id, academic_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS discount_audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    discount_id BIGINT NULL,
    debt_id INT NULL,
    student_id INT NULL,
    user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    details LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_discount_audit_school (school_id, created_at),
    INDEX idx_discount_audit_discount (discount_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Incorporar descuentos antiguos sin duplicarlos ni cambiar sus importes actuales.
INSERT INTO discount_benefits
    (school_id, academic_year_id, student_id, debt_id, course_id, benefit_type,
     calculation_type, value, original_amount, paid_before, previous_effective_amount,
     final_effective_amount, discount_amount, scope_type, reason, notes, status, created_at)
SELECT s.school_id, c.academic_year_id, ef.student_id, ef.id, ef.course_id, 'Migrado',
       'Monto final', ef.discounted_amount, ef.total_fee,
       COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.ef_id=ef.id AND COALESCE(p.payment_status,'Confirmado')='Confirmado'),0),
       ef.total_fee, ef.discounted_amount, GREATEST(ef.total_fee-ef.discounted_amount,0),
       'Deuda', 'Descuento existente antes de la actualización', 'Registro migrado automáticamente por el script.', 'Aplicado', COALESCE(ef.date_created,CURRENT_TIMESTAMP)
FROM student_ef_list ef
INNER JOIN student s ON s.id=ef.student_id
INNER JOIN courses c ON c.id=ef.course_id
WHERE ef.discounted_amount IS NOT NULL
  AND ef.discounted_amount < ef.total_fee
  AND NOT EXISTS (SELECT 1 FROM discount_benefits db WHERE db.debt_id=ef.id AND db.status='Aplicado');
