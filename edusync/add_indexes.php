<?php
include 'db_connect.php';

echo "Agregando índices de rendimiento...\n";

// Índice en payments.ef_id (acelera la subquery de suma de pagos)
$r = $conn->query("SHOW INDEX FROM payments WHERE Key_name = 'idx_payments_ef_id'");
if (!$r || $r->num_rows == 0) {
    if ($conn->query("ALTER TABLE payments ADD INDEX idx_payments_ef_id (ef_id)")) {
        echo "✅ Índice payments.ef_id creado\n";
    } else {
        echo "⚠️ payments.ef_id: " . $conn->error . "\n";
    }
} else {
    echo "✅ payments.ef_id ya existe\n";
}

// Índice en student_ef_list.student_id
$r = $conn->query("SHOW INDEX FROM student_ef_list WHERE Key_name = 'idx_sef_student_id'");
if (!$r || $r->num_rows == 0) {
    if ($conn->query("ALTER TABLE student_ef_list ADD INDEX idx_sef_student_id (student_id)")) {
        echo "✅ Índice student_ef_list.student_id creado\n";
    } else {
        echo "⚠️ student_ef_list.student_id: " . $conn->error . "\n";
    }
} else {
    echo "✅ student_ef_list.student_id ya existe\n";
}

// Índice en student_ef_list.course_id
$r = $conn->query("SHOW INDEX FROM student_ef_list WHERE Key_name = 'idx_sef_course_id'");
if (!$r || $r->num_rows == 0) {
    if ($conn->query("ALTER TABLE student_ef_list ADD INDEX idx_sef_course_id (course_id)")) {
        echo "✅ Índice student_ef_list.course_id creado\n";
    } else {
        echo "⚠️ student_ef_list.course_id: " . $conn->error . "\n";
    }
} else {
    echo "✅ student_ef_list.course_id ya existe\n";
}

// Índice en student.school_id
$r = $conn->query("SHOW INDEX FROM student WHERE Key_name = 'idx_student_school_id'");
if (!$r || $r->num_rows == 0) {
    if ($conn->query("ALTER TABLE student ADD INDEX idx_student_school_id (school_id)")) {
        echo "✅ Índice student.school_id creado\n";
    } else {
        echo "⚠️ student.school_id: " . $conn->error . "\n";
    }
} else {
    echo "✅ student.school_id ya existe\n";
}

echo "\n¡Listo! Los índices han sido verificados/creados.\n";
