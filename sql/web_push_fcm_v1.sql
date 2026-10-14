-- EduSync: FCM Web Push independiente del registro de dispositivos Android.
-- Ejecutar MANUALMENTE en la BD de pruebas, tras backup. No ejecutarlo
-- automáticamente desde una petición web.
CREATE TABLE IF NOT EXISTS web_push_subscriptions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NOT NULL,
  account_kind VARCHAR(12) NOT NULL,
  account_id INT NOT NULL,
  role_type TINYINT NOT NULL DEFAULT 4,
  browser_id VARCHAR(100) NOT NULL,
  fcm_token VARCHAR(1024) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  subscribed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_web_push_browser (browser_id),
  UNIQUE KEY uq_web_push_token (token_hash),
  INDEX ix_web_push_target (school_id,account_kind,account_id,is_active),
  INDEX ix_web_push_last_seen (is_active,last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS web_push_delivery (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NOT NULL,
  subscription_id BIGINT NOT NULL,
  event_kind VARCHAR(12) NOT NULL,
  event_id BIGINT NOT NULL,
  status VARCHAR(18) NOT NULL DEFAULT 'retry',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  provider_code INT NOT NULL DEFAULT 0,
  provider_message VARCHAR(500) NULL,
  next_retry_at DATETIME NULL,
  delivered_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_web_push_event_delivery (subscription_id,event_kind,event_id),
  INDEX ix_web_push_delivery_retry (status,next_retry_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
