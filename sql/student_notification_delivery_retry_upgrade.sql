-- Reintentos seguros del Centro de Notificaciones.
-- Ejecutar una vez en bases que ya tenían student_push_notifications.sql aplicado.

SET @schema_name = DATABASE();

SET @add_event_id = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE push_notification_log ADD COLUMN notification_event_id BIGINT NULL AFTER attendance_id',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'push_notification_log'
      AND COLUMN_NAME = 'notification_event_id'
);
PREPARE stmt FROM @add_event_id;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_event_index = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE push_notification_log ADD INDEX idx_push_log_event_device (notification_event_id, device_token_id, delivery_status)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'push_notification_log'
      AND INDEX_NAME = 'idx_push_log_event_device'
);
PREPARE stmt FROM @add_event_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
