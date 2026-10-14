-- EduSync: acceso recordado 30 días por navegador, revocable al cerrar sesión.
-- Ejecutar MANUALMENTE SOLO EN BD DE PRUEBAS, tras copia de seguridad.
-- No guarda contraseñas ni tokens en texto claro.
CREATE TABLE IF NOT EXISTS remembered_login_devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  selector CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  school_id INT NOT NULL,
  account_kind VARCHAR(12) NOT NULL,
  account_id INT NOT NULL,
  credential_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL,
  last_used_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL DEFAULT NULL,
  UNIQUE KEY uq_remember_selector (selector),
  INDEX ix_remember_account (school_id,account_kind,account_id,revoked_at),
  INDEX ix_remember_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
