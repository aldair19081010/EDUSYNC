<?php
// Read-only report permission; does not grant administrative or grading rights.
function grades_report_is_director($conn) {
    $userId = (int)($_SESSION['login_id'] ?? 0);
    $schoolId = (int)($_SESSION['login_school_id'] ?? 0);
    if (!$userId || !$schoolId) return false;
    $stmt = $conn->prepare('SELECT is_director FROM users WHERE id = ? AND school_id = ? LIMIT 1');
    $stmt->bind_param('ii', $userId, $schoolId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && (int)$row['is_director'] === 1;
}
