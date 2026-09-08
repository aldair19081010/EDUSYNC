-- Cierre bimestral de notas por asignación docente.
-- Ejecutar MANUALMENTE una sola vez en cada base de datos.

CREATE TABLE IF NOT EXISTS grade_period_closures (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    teacher_course_id INT NOT NULL,
    teacher_id INT NOT NULL,
    bimester TINYINT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Abierto',
    closed_by INT NULL,
    closed_at DATETIME NULL,
    closure_snapshot LONGTEXT NULL,
    closure_version INT NOT NULL DEFAULT 0,
    reopened_by INT NULL,
    reopened_at DATETIME NULL,
    reopen_reason VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_grade_period_closure (school_id, teacher_course_id, bimester),
    INDEX idx_grade_period_status (school_id, academic_year_id, bimester, status),
    INDEX idx_grade_period_teacher (school_id, teacher_id, academic_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS grade_reopen_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    closure_id BIGINT NOT NULL,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    teacher_course_id INT NOT NULL,
    teacher_id INT NOT NULL,
    bimester TINYINT NOT NULL,
    requested_by INT NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Pendiente',
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_notes VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_grade_reopen_school (school_id, status, created_at),
    INDEX idx_grade_reopen_closure (closure_id, status),
    INDEX idx_grade_reopen_teacher (school_id, teacher_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS grade_period_audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    teacher_course_id INT NOT NULL,
    teacher_id INT NULL,
    bimester TINYINT NOT NULL,
    user_id INT NULL,
    action VARCHAR(50) NOT NULL,
    details LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_grade_period_audit (school_id, academic_year_id, bimester, created_at),
    INDEX idx_grade_period_audit_assignment (teacher_course_id, bimester, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Defensa a nivel de base de datos: aunque una pantalla antigua intente escribir,
-- un periodo cerrado permanece inmutable hasta que Administración autorice la reapertura.
DROP TRIGGER IF EXISTS trg_grade_close_eval_insert;
DROP TRIGGER IF EXISTS trg_grade_close_eval_update;
DROP TRIGGER IF EXISTS trg_grade_close_eval_delete;
DROP TRIGGER IF EXISTS trg_grade_close_grade_insert;
DROP TRIGGER IF EXISTS trg_grade_close_grade_update;
DROP TRIGGER IF EXISTS trg_grade_close_grade_delete;
DROP TRIGGER IF EXISTS trg_grade_close_comp_insert;
DROP TRIGGER IF EXISTS trg_grade_close_comp_update;
DROP TRIGGER IF EXISTS trg_grade_close_comp_delete;

DELIMITER $$

CREATE TRIGGER trg_grade_close_eval_insert
BEFORE INSERT ON evaluations
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM grade_period_closures gpc
        INNER JOIN teacher_courses tc ON tc.id = NEW.teacher_course_id AND tc.school_id = gpc.school_id
        WHERE gpc.teacher_course_id = NEW.teacher_course_id
          AND gpc.bimester = CAST(NEW.bimestre AS UNSIGNED)
          AND gpc.status = 'Cerrado'
        LIMIT 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El bimestre está cerrado. Solicite una reapertura antes de crear evaluaciones.';
    END IF;
END$$

CREATE TRIGGER trg_grade_close_eval_update
BEFORE UPDATE ON evaluations
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM grade_period_closures gpc
        WHERE gpc.teacher_course_id = OLD.teacher_course_id
          AND gpc.bimester = CAST(OLD.bimestre AS UNSIGNED)
          AND gpc.status = 'Cerrado'
        LIMIT 1
    ) OR EXISTS (
        SELECT 1 FROM grade_period_closures gpc
        WHERE gpc.teacher_course_id = NEW.teacher_course_id
          AND gpc.bimester = CAST(NEW.bimestre AS UNSIGNED)
          AND gpc.status = 'Cerrado'
        LIMIT 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El bimestre está cerrado. Solicite una reapertura antes de modificar evaluaciones.';
    END IF;
END$$

CREATE TRIGGER trg_grade_close_eval_delete
BEFORE DELETE ON evaluations
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM grade_period_closures gpc
        WHERE gpc.teacher_course_id = OLD.teacher_course_id
          AND gpc.bimester = CAST(OLD.bimestre AS UNSIGNED)
          AND gpc.status = 'Cerrado'
        LIMIT 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El bimestre está cerrado. No se puede eliminar ni anular una evaluación.';
    END IF;
END$$

CREATE TRIGGER trg_grade_close_grade_insert
BEFORE INSERT ON evaluation_grades
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM evaluations e
        INNER JOIN grade_period_closures gpc
            ON gpc.teacher_course_id = e.teacher_course_id
           AND gpc.bimester = CAST(e.bimestre AS UNSIGNED)
           AND gpc.status = 'Cerrado'
        WHERE e.id = NEW.evaluation_id
        LIMIT 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las notas de este bimestre están cerradas. Solicite una reapertura.';
    END IF;
END$$

CREATE TRIGGER trg_grade_close_grade_update
BEFORE UPDATE ON evaluation_grades
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM evaluations e
        INNER JOIN grade_period_closures gpc
            ON gpc.teacher_course_id = e.teacher_course_id
           AND gpc.bimester = CAST(e.bimestre AS UNSIGNED)
           AND gpc.status = 'Cerrado'
        WHERE e.id IN (OLD.evaluation_id, NEW.evaluation_id)
        LIMIT 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las notas de este bimestre están cerradas. Solicite una reapertura.';
    END IF;
END$$

CREATE TRIGGER trg_grade_close_grade_delete
BEFORE DELETE ON evaluation_grades
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM evaluations e
        INNER JOIN grade_period_closures gpc
            ON gpc.teacher_course_id = e.teacher_course_id
           AND gpc.bimester = CAST(e.bimestre AS UNSIGNED)
           AND gpc.status = 'Cerrado'
        WHERE e.id = OLD.evaluation_id
        LIMIT 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las notas de este bimestre están cerradas. Solicite una reapertura.';
    END IF;
END$$

-- Las competencias son compartidas por los grados del mismo curso/docente/año.
-- Si al menos una asignación de ese curso ya fue cerrada, se congelan para proteger
-- el porcentaje y el promedio histórico guardado en el cierre.
CREATE TRIGGER trg_grade_close_comp_insert
BEFORE INSERT ON general_course_competencies
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM teacher_courses tc
        INNER JOIN grade_period_closures gpc
            ON gpc.teacher_course_id = tc.id
           AND gpc.status = 'Cerrado'
        WHERE tc.course_id = NEW.course_id
          AND tc.teacher_id = NEW.teacher_id
          AND tc.academic_year_id = NEW.academic_year_id
        LIMIT 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las competencias están protegidas porque este curso ya tiene notas cerradas.';
    END IF;
END$$

CREATE TRIGGER trg_grade_close_comp_update
BEFORE UPDATE ON general_course_competencies
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM teacher_courses tc
        INNER JOIN grade_period_closures gpc
            ON gpc.teacher_course_id = tc.id
           AND gpc.status = 'Cerrado'
        WHERE tc.course_id = OLD.course_id
          AND tc.teacher_id = OLD.teacher_id
          AND tc.academic_year_id = OLD.academic_year_id
        LIMIT 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las competencias están protegidas porque este curso ya tiene notas cerradas.';
    END IF;
END$$

CREATE TRIGGER trg_grade_close_comp_delete
BEFORE DELETE ON general_course_competencies
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM teacher_courses tc
        INNER JOIN grade_period_closures gpc
            ON gpc.teacher_course_id = tc.id
           AND gpc.status = 'Cerrado'
        WHERE tc.course_id = OLD.course_id
          AND tc.teacher_id = OLD.teacher_id
          AND tc.academic_year_id = OLD.academic_year_id
        LIMIT 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las competencias están protegidas porque este curso ya tiene notas cerradas.';
    END IF;
END$$

DELIMITER ;
