ALTER TABLE teacher_employment_history
    ADD COLUMN IF NOT EXISTS departure_reason VARCHAR(50) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER departure_reason;
