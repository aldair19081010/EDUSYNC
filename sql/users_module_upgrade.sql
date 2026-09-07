-- Mejora del módulo de usuarios de EduSync
-- Ejecutar una sola vez en cada base de datos antes de usar la nueva pantalla.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS status ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo' AFTER teacher_id,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

UPDATE users SET status = 'Activo' WHERE status IS NULL OR status = '';

CREATE TABLE IF NOT EXISTS user_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id INT(11) NOT NULL,
    target_user_id INT(30) DEFAULT NULL,
    target_username VARCHAR(200) DEFAULT NULL,
    actor_user_id INT(30) DEFAULT NULL,
    action VARCHAR(40) NOT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(64) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_audit_school (school_id),
    KEY idx_user_audit_target (target_user_id),
    KEY idx_user_audit_actor (actor_user_id),
    KEY idx_user_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Índices auxiliares. Se crean solo si todavía no existen.
SET @idx_status_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_school_status'
);
SET @sql_status_idx = IF(@idx_status_exists = 0,
    'CREATE INDEX idx_users_school_status ON users (school_id, status)',
    'SELECT 1');
PREPARE stmt_status_idx FROM @sql_status_idx;
EXECUTE stmt_status_idx;
DEALLOCATE PREPARE stmt_status_idx;

SET @idx_role_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_school_type'
);
SET @sql_role_idx = IF(@idx_role_exists = 0,
    'CREATE INDEX idx_users_school_type ON users (school_id, type)',
    'SELECT 1');
PREPARE stmt_role_idx FROM @sql_role_idx;
EXECUTE stmt_role_idx;
DEALLOCATE PREPARE stmt_role_idx;
