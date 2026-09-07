-- Trazabilidad para correcciones de pagos.
-- Ejecutar manualmente una sola vez después de payments_module_upgrade.sql.

ALTER TABLE payment_operations
    ADD COLUMN IF NOT EXISTS corrected_from_id BIGINT NULL AFTER cancellation_reason,
    ADD COLUMN IF NOT EXISTS corrected_by_id BIGINT NULL AFTER corrected_from_id,
    ADD COLUMN IF NOT EXISTS correction_reason VARCHAR(255) NULL AFTER corrected_by_id,
    ADD INDEX IF NOT EXISTS idx_payment_corrected_from (corrected_from_id),
    ADD INDEX IF NOT EXISTS idx_payment_corrected_by (corrected_by_id);
