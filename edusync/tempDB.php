<?php require 'db_connect.php'; $conn->query('ALTER TABLE users ADD is_director TINYINT(1) DEFAULT 0'); echo 'success';
