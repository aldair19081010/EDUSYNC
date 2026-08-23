<?php
include 'db_connect.php';
$q = $conn->query("SELECT * FROM users WHERE is_director = 1");
if ($q->num_rows > 0) {
    echo "Directores encontrados:\n";
    while ($r = $q->fetch_assoc()) {
        print_r($r);
    }
} else {
    echo "No hay directores.\n";
}
