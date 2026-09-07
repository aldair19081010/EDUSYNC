-- Descuentos autorizados durante el registro de pagos.
-- Ejecutar manualmente una sola vez después de payments_module_upgrade.sql.

CREATE TABLE IF NOT EXISTS debt_discounts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    debt_id INT NOT NULL,
    payment_operation_id BIGINT NOT NULL,
    discount_type VARCHAR(20) NOT NULL,
    discount_value DECIMAL(12,2) NOT NULL,
    discount_amount DECIMAL(12,2) NOT NULL,
    previous_effective_amount DECIMAL(12,2) NOT NULL,
    previous_discounted_amount DECIMAL(12,2) NULL,
    final_effective_amount DECIMAL(12,2) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Aplicado',
    authorized_by INT NOT NULL,
    reversed_at DATETIME NULL,
    reversed_by INT NULL,
    reversal_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_debt_discount_debt (debt_id, status),
    INDEX idx_debt_discount_operation (payment_operation_id),
    INDEX idx_debt_discount_school (school_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

