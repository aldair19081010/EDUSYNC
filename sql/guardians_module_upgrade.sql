-- Módulo de Apoderados de EduSync.
-- Puede ejecutarse nuevamente: además de crear las tablas, sincroniza los tutores
-- ya registrados en student y deja activos los triggers para futuras altas/cambios.

-- Compatibilidad con instalaciones antiguas del módulo de usuarios.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS status ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo' AFTER teacher_id;

CREATE TABLE IF NOT EXISTS guardians (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id INT NOT NULL,
    user_id INT NULL,
    dni VARCHAR(20) NOT NULL,
    nombres VARCHAR(120) NOT NULL,
    apellido_paterno VARCHAR(160) NOT NULL,
    apellido_materno VARCHAR(80) NULL,
    telefono VARCHAR(30) NULL,
    email VARCHAR(160) NULL,
    direccion VARCHAR(255) NULL,
    status ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_guardian_school_dni (school_id, dni),
    UNIQUE KEY uq_guardian_school_user (school_id, user_id),
    KEY idx_guardian_school_status (school_id, status),
    KEY idx_guardian_name (school_id, apellido_paterno, apellido_materno, nombres)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Actualizaciones para instalaciones que ya ejecutaron una versión anterior.
ALTER TABLE guardians
    MODIFY COLUMN apellido_paterno VARCHAR(160) NOT NULL;
ALTER TABLE guardians
    ADD COLUMN IF NOT EXISTS direccion VARCHAR(255) NULL AFTER email;

CREATE TABLE IF NOT EXISTS guardian_students (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id INT NOT NULL,
    guardian_id BIGINT UNSIGNED NOT NULL,
    student_id INT NOT NULL,
    parentesco VARCHAR(40) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    can_view_grades TINYINT(1) NOT NULL DEFAULT 1,
    can_view_attendance TINYINT(1) NOT NULL DEFAULT 1,
    can_view_payments TINYINT(1) NOT NULL DEFAULT 1,
    can_receive_communications TINYINT(1) NOT NULL DEFAULT 1,
    source_type ENUM('Manual','StudentForm') NOT NULL DEFAULT 'Manual',
    source_slot TINYINT UNSIGNED NULL,
    status ENUM('Activo','Inactivo') NOT NULL DEFAULT 'Activo',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_guardian_student (school_id, guardian_id, student_id),
    KEY idx_guardian_students_guardian (school_id, guardian_id, status),
    KEY idx_guardian_students_student (school_id, student_id, status),
    KEY idx_guardian_students_primary (school_id, student_id, is_primary, status),
    KEY idx_guardian_students_source (school_id, student_id, source_type, source_slot, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE guardian_students
    ADD COLUMN IF NOT EXISTS source_type ENUM('Manual','StudentForm') NOT NULL DEFAULT 'Manual' AFTER can_receive_communications;
ALTER TABLE guardian_students
    ADD COLUMN IF NOT EXISTS source_slot TINYINT UNSIGNED NULL AFTER source_type;

SET @idx_guardian_source_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'guardian_students'
      AND index_name = 'idx_guardian_students_source'
);
SET @sql_guardian_source_idx = IF(
    @idx_guardian_source_exists = 0,
    'CREATE INDEX idx_guardian_students_source ON guardian_students (school_id, student_id, source_type, source_slot, status)',
    'SELECT 1'
);
PREPARE guardian_source_stmt FROM @sql_guardian_source_idx;
EXECUTE guardian_source_stmt;
DEALLOCATE PREPARE guardian_source_stmt;

CREATE TABLE IF NOT EXISTS guardian_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_id INT NOT NULL,
    guardian_id BIGINT UNSIGNED NOT NULL,
    actor_user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    details LONGTEXT NULL,
    ip_address VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_guardian_audit_guardian (school_id, guardian_id, created_at),
    KEY idx_guardian_audit_actor (school_id, actor_user_id),
    KEY idx_guardian_audit_date (school_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La ficha del alumno es la fuente automática de Tutor Principal / Tutor Secundario.
-- Un mismo DNI se convierte en un único apoderado y puede quedar vinculado a varios hijos.
-- No se genera una clave de acceso durante esta sincronización: user_id queda NULL hasta
-- que Administración cree/restablezca una clave desde el módulo Apoderados.

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_edusync_sync_student_guardians$$
CREATE PROCEDURE sp_edusync_sync_student_guardians(IN p_student_id INT, IN p_school_id INT)
proc: BEGIN
    DECLARE v_tutor1_nombre VARCHAR(120) DEFAULT '';
    DECLARE v_tutor1_apellido VARCHAR(160) DEFAULT '';
    DECLARE v_tutor1_dni VARCHAR(20) DEFAULT '';
    DECLARE v_tutor1_telefono VARCHAR(30) DEFAULT '';
    DECLARE v_tutor1_direccion VARCHAR(255) DEFAULT '';
    DECLARE v_tutor1_relacion VARCHAR(40) DEFAULT '';
    DECLARE v_tutor2_nombre VARCHAR(120) DEFAULT '';
    DECLARE v_tutor2_apellido VARCHAR(160) DEFAULT '';
    DECLARE v_tutor2_dni VARCHAR(20) DEFAULT '';
    DECLARE v_tutor2_telefono VARCHAR(30) DEFAULT '';
    DECLARE v_tutor2_direccion VARCHAR(255) DEFAULT '';
    DECLARE v_tutor2_relacion VARCHAR(40) DEFAULT '';
    DECLARE v_guardian_id BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_existing_source VARCHAR(20) DEFAULT NULL;

    IF p_student_id <= 0 OR p_school_id <= 0 THEN
        LEAVE proc;
    END IF;

    SELECT
        TRIM(COALESCE(tutor1_nombre,'')), TRIM(COALESCE(tutor1_apellido,'')),
        TRIM(COALESCE(tutor1_dni,'')), TRIM(COALESCE(tutor1_telefono,'')),
        TRIM(COALESCE(tutor1_direccion,'')), TRIM(COALESCE(tutor1_relacion,'')),
        TRIM(COALESCE(tutor2_nombre,'')), TRIM(COALESCE(tutor2_apellido,'')),
        TRIM(COALESCE(tutor2_dni,'')), TRIM(COALESCE(tutor2_telefono,'')),
        TRIM(COALESCE(tutor2_direccion,'')), TRIM(COALESCE(tutor2_relacion,''))
    INTO
        v_tutor1_nombre, v_tutor1_apellido, v_tutor1_dni, v_tutor1_telefono,
        v_tutor1_direccion, v_tutor1_relacion,
        v_tutor2_nombre, v_tutor2_apellido, v_tutor2_dni, v_tutor2_telefono,
        v_tutor2_direccion, v_tutor2_relacion
    FROM student
    WHERE id = p_student_id AND school_id = p_school_id
    LIMIT 1;

    -- Desactivar solo vínculos generados por la ficha; los vínculos manuales se conservan.
    UPDATE guardian_students
    SET status = 'Inactivo'
    WHERE school_id = p_school_id
      AND student_id = p_student_id
      AND source_type = 'StudentForm';

    -- Tutor principal. Para evitar duplicados, DNI/documento es la clave de identidad.
    IF v_tutor1_dni REGEXP '^[0-9]{8,12}$'
       AND (v_tutor1_nombre <> '' OR v_tutor1_apellido <> '') THEN

        INSERT INTO guardians
            (school_id, user_id, dni, nombres, apellido_paterno, apellido_materno, telefono, email, direccion, status)
        VALUES
            (p_school_id, NULL, v_tutor1_dni,
             IF(v_tutor1_nombre <> '', v_tutor1_nombre, 'Sin nombres'),
             IF(v_tutor1_apellido <> '', v_tutor1_apellido, 'Sin apellidos'),
             NULL, NULLIF(v_tutor1_telefono,''), NULL, NULLIF(v_tutor1_direccion,''), 'Activo')
        ON DUPLICATE KEY UPDATE
            nombres = IF(VALUES(nombres) <> 'Sin nombres', VALUES(nombres), guardians.nombres),
            apellido_paterno = IF(VALUES(apellido_paterno) <> 'Sin apellidos', VALUES(apellido_paterno), guardians.apellido_paterno),
            telefono = COALESCE(NULLIF(VALUES(telefono),''), guardians.telefono),
            direccion = COALESCE(NULLIF(VALUES(direccion),''), guardians.direccion),
            status = 'Activo';

        SELECT id INTO v_guardian_id
        FROM guardians
        WHERE school_id = p_school_id AND dni = v_tutor1_dni
        LIMIT 1;

        SET v_existing_source = NULL;
        SELECT source_type INTO v_existing_source
        FROM guardian_students
        WHERE school_id = p_school_id AND guardian_id = v_guardian_id AND student_id = p_student_id
        LIMIT 1;

        IF v_existing_source IS NULL THEN
            INSERT INTO guardian_students
                (school_id, guardian_id, student_id, parentesco, is_primary,
                 can_view_grades, can_view_attendance, can_view_payments, can_receive_communications,
                 source_type, source_slot, status)
            VALUES
                (p_school_id, v_guardian_id, p_student_id,
                 IF(v_tutor1_relacion <> '', v_tutor1_relacion, 'Apoderado'), 1,
                 1,1,1,1,'StudentForm',1,'Activo');
        ELSEIF v_existing_source = 'StudentForm' THEN
            UPDATE guardian_students
            SET parentesco = IF(v_tutor1_relacion <> '', v_tutor1_relacion, parentesco),
                is_primary = 1,
                source_slot = 1,
                status = 'Activo'
            WHERE school_id = p_school_id
              AND guardian_id = v_guardian_id
              AND student_id = p_student_id;
        ELSE
            -- Si ya existía un vínculo manual, no lo convertimos en automático.
            UPDATE guardian_students
            SET is_primary = 1,
                parentesco = IF(v_tutor1_relacion <> '', v_tutor1_relacion, parentesco),
                status = 'Activo'
            WHERE school_id = p_school_id
              AND guardian_id = v_guardian_id
              AND student_id = p_student_id;
        END IF;
    END IF;

    -- Tutor secundario. Si repite el mismo DNI del principal no se crea un segundo vínculo.
    IF v_tutor2_dni REGEXP '^[0-9]{8,12}$'
       AND (v_tutor2_nombre <> '' OR v_tutor2_apellido <> '')
       AND v_tutor2_dni <> v_tutor1_dni THEN

        INSERT INTO guardians
            (school_id, user_id, dni, nombres, apellido_paterno, apellido_materno, telefono, email, direccion, status)
        VALUES
            (p_school_id, NULL, v_tutor2_dni,
             IF(v_tutor2_nombre <> '', v_tutor2_nombre, 'Sin nombres'),
             IF(v_tutor2_apellido <> '', v_tutor2_apellido, 'Sin apellidos'),
             NULL, NULLIF(v_tutor2_telefono,''), NULL, NULLIF(v_tutor2_direccion,''), 'Activo')
        ON DUPLICATE KEY UPDATE
            nombres = IF(VALUES(nombres) <> 'Sin nombres', VALUES(nombres), guardians.nombres),
            apellido_paterno = IF(VALUES(apellido_paterno) <> 'Sin apellidos', VALUES(apellido_paterno), guardians.apellido_paterno),
            telefono = COALESCE(NULLIF(VALUES(telefono),''), guardians.telefono),
            direccion = COALESCE(NULLIF(VALUES(direccion),''), guardians.direccion),
            status = 'Activo';

        SELECT id INTO v_guardian_id
        FROM guardians
        WHERE school_id = p_school_id AND dni = v_tutor2_dni
        LIMIT 1;

        SET v_existing_source = NULL;
        SELECT source_type INTO v_existing_source
        FROM guardian_students
        WHERE school_id = p_school_id AND guardian_id = v_guardian_id AND student_id = p_student_id
        LIMIT 1;

        IF v_existing_source IS NULL THEN
            INSERT INTO guardian_students
                (school_id, guardian_id, student_id, parentesco, is_primary,
                 can_view_grades, can_view_attendance, can_view_payments, can_receive_communications,
                 source_type, source_slot, status)
            VALUES
                (p_school_id, v_guardian_id, p_student_id,
                 IF(v_tutor2_relacion <> '', v_tutor2_relacion, 'Apoderado'), 0,
                 1,1,1,1,'StudentForm',2,'Activo');
        ELSEIF v_existing_source = 'StudentForm' THEN
            UPDATE guardian_students
            SET parentesco = IF(v_tutor2_relacion <> '', v_tutor2_relacion, parentesco),
                is_primary = 0,
                source_slot = 2,
                status = 'Activo'
            WHERE school_id = p_school_id
              AND guardian_id = v_guardian_id
              AND student_id = p_student_id;
        ELSE
            UPDATE guardian_students
            SET parentesco = IF(v_tutor2_relacion <> '', v_tutor2_relacion, parentesco),
                status = 'Activo'
            WHERE school_id = p_school_id
              AND guardian_id = v_guardian_id
              AND student_id = p_student_id;
        END IF;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_student_guardians_after_insert$$
CREATE TRIGGER trg_student_guardians_after_insert
AFTER INSERT ON student
FOR EACH ROW
BEGIN
    CALL sp_edusync_sync_student_guardians(NEW.id, NEW.school_id);
END$$

DROP TRIGGER IF EXISTS trg_student_guardians_after_update$$
CREATE TRIGGER trg_student_guardians_after_update
AFTER UPDATE ON student
FOR EACH ROW
BEGIN
    IF NOT (NEW.tutor1_nombre <=> OLD.tutor1_nombre)
       OR NOT (NEW.tutor1_apellido <=> OLD.tutor1_apellido)
       OR NOT (NEW.tutor1_dni <=> OLD.tutor1_dni)
       OR NOT (NEW.tutor1_telefono <=> OLD.tutor1_telefono)
       OR NOT (NEW.tutor1_direccion <=> OLD.tutor1_direccion)
       OR NOT (NEW.tutor1_relacion <=> OLD.tutor1_relacion)
       OR NOT (NEW.tutor2_nombre <=> OLD.tutor2_nombre)
       OR NOT (NEW.tutor2_apellido <=> OLD.tutor2_apellido)
       OR NOT (NEW.tutor2_dni <=> OLD.tutor2_dni)
       OR NOT (NEW.tutor2_telefono <=> OLD.tutor2_telefono)
       OR NOT (NEW.tutor2_direccion <=> OLD.tutor2_direccion)
       OR NOT (NEW.tutor2_relacion <=> OLD.tutor2_relacion) THEN
        CALL sp_edusync_sync_student_guardians(NEW.id, NEW.school_id);
    END IF;
END$$

-- Sincronización inicial de todos los alumnos que ya tenían tutores registrados.
DROP PROCEDURE IF EXISTS sp_edusync_backfill_student_guardians$$
CREATE PROCEDURE sp_edusync_backfill_student_guardians()
BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_student_id INT;
    DECLARE v_school_id INT;
    DECLARE cur_students CURSOR FOR
        SELECT id, school_id FROM student WHERE school_id IS NOT NULL AND school_id > 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN cur_students;
    sync_loop: LOOP
        FETCH cur_students INTO v_student_id, v_school_id;
        IF v_done = 1 THEN
            LEAVE sync_loop;
        END IF;
        CALL sp_edusync_sync_student_guardians(v_student_id, v_school_id);
    END LOOP;
    CLOSE cur_students;
END$$

CALL sp_edusync_backfill_student_guardians()$$
DROP PROCEDURE IF EXISTS sp_edusync_backfill_student_guardians$$

DELIMITER ;
