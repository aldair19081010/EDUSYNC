-- Enlaces seguros y no predecibles para compartir recibos.
-- Ejecutar manualmente una sola vez después de payments_module_upgrade.sql.

CREATE TABLE IF NOT EXISTS receipt_share_tokens (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    operation_id BIGINT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_receipt_share_token (token_hash),
    INDEX idx_receipt_share_operation (school_id, operation_id),
    INDEX idx_receipt_share_expiry (expires_at, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
