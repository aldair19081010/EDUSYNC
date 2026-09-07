-- Limpieza opcional de la conciliación digital descartada.
-- Ejecutar solamente si anteriormente se ejecutó cash_reconciliation_upgrade.sql.

ALTER TABLE payment_operation_methods
    DROP INDEX IF EXISTS idx_payment_reconciliation,
    DROP COLUMN IF EXISTS reconciled_by,
    DROP COLUMN IF EXISTS reconciled_at,
    DROP COLUMN IF EXISTS reconciliation_notes,
    DROP COLUMN IF EXISTS reconciliation_status;
