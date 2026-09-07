-- Cierre y reporte de caja.
-- Ejecutar manualmente una sola vez después de payments_module_upgrade.sql.

ALTER TABLE cash_sessions
    ADD COLUMN IF NOT EXISTS operation_count INT NOT NULL DEFAULT 0 AFTER expected_balance,
    ADD COLUMN IF NOT EXISTS cancelled_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER operation_count,
    ADD COLUMN IF NOT EXISTS closed_by INT NULL AFTER notes;

-- Completar los cierres históricos sin alterar sus importes ya registrados.
UPDATE cash_sessions cs
SET cs.operation_count = (
        SELECT COUNT(*) FROM payment_operations po
        WHERE po.cash_session_id = cs.id AND po.school_id = cs.school_id AND po.status = 'Confirmado'
    ),
    cs.cancelled_total = COALESCE((
        SELECT SUM(po.total_amount) FROM payment_operations po
        WHERE po.cash_session_id = cs.id AND po.school_id = cs.school_id AND po.status = 'Anulado'
    ), 0);
