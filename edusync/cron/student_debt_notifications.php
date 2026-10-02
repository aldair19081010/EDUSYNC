<?php
/**
 * Daily student debt notification job.
 *
 * Run once per day from CLI:
 * php edusync/cron/student_debt_notifications.php
 *
 * Stages are deduplicated by debt:
 * - 3 days before due date
 * - due date
 * - first run after it becomes overdue
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

date_default_timezone_set('America/Lima');

$options = getopt('', ['student-id:', 'debt-id:', 'credentials:']);
$studentFilter = max(0, (int)($options['student-id'] ?? 0));
$debtFilter = max(0, (int)($options['debt-id'] ?? 0));
$credentialsPath = trim((string)($options['credentials'] ?? ''));

if ($credentialsPath !== '') {
    putenv('EDUSYNC_FIREBASE_CREDENTIALS=' . $credentialsPath);
    $_ENV['EDUSYNC_FIREBASE_CREDENTIALS'] = $credentialsPath;
}

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../includes/push_notifications.php';

$conn->query("SET time_zone = '-05:00'");

$extraWhere = '';
if ($studentFilter > 0) $extraWhere .= ' AND ef.student_id=' . $studentFilter;
if ($debtFilter > 0) $extraWhere .= ' AND ef.id=' . $debtFilter;

$sql = "
    SELECT
        ef.id debt_id,
        ef.student_id,
        s.school_id,
        COALESCE(NULLIF(TRIM(c.course),''),'Obligación pendiente') concept_name,
        ef.due_date,
        COALESCE(ef.discounted_amount,ef.total_fee) effective_amount,
        COALESCE(SUM(CASE WHEN p.payment_status='Confirmado' THEN p.amount ELSE 0 END),0) paid_amount
    FROM student_ef_list ef
    INNER JOIN student s
        ON s.id=ef.student_id
       AND s.status='Activo'
    LEFT JOIN courses c
        ON c.id=ef.course_id
    LEFT JOIN payments p
        ON p.ef_id=ef.id
    WHERE ef.debt_status='Activa' $extraWhere
    GROUP BY
        ef.id,
        ef.student_id,
        s.school_id,
        c.course,
        ef.due_date,
        ef.discounted_amount,
        ef.total_fee
    HAVING
        (effective_amount-paid_amount)>0.009
        AND (
            due_date IS NULL
            OR due_date=''
            OR due_date<CURDATE()
            OR DATEDIFF(due_date,CURDATE()) IN (0,3)
        )
    ORDER BY s.school_id,ef.student_id,ef.id
";

$result = $conn->query($sql);
if (!$result) {
    fwrite(STDERR, "[debt notifications] SQL error: {$conn->error}\n");
    exit(1);
}

$stats = [
    'candidates' => 0,
    'created' => 0,
    'retried' => 0,
    'duplicates' => 0,
    'pending_push' => 0,
    'sent' => 0,
    'failed' => 0,
];

$today = new DateTimeImmutable('today');

while ($row = $result->fetch_assoc()) {
    $stats['candidates']++;

    $dueDateRaw = trim((string)($row['due_date'] ?? ''));
    $stage = 'overdue';

    if ($dueDateRaw !== '') {
        $dueDate = DateTimeImmutable::createFromFormat('Y-m-d', substr($dueDateRaw, 0, 10));
        if ($dueDate) {
            $days = (int)$today->diff($dueDate)->format('%r%a');
            if ($days === 3) {
                $stage = 'upcoming3';
            } elseif ($days === 0) {
                $stage = 'due_today';
            } elseif ($days < 0) {
                $stage = 'overdue';
            } else {
                continue;
            }
        }
    }

    $effective = (float)$row['effective_amount'];
    $paid = (float)$row['paid_amount'];
    $balance = max(0, round($effective - $paid, 2));
    if ($balance <= 0.009) continue;

    try {
        $send = push_send_debt_notification(
            $conn,
            (int)$row['school_id'],
            (int)$row['student_id'],
            (int)$row['debt_id'],
            (string)$row['concept_name'],
            $balance,
            $dueDateRaw !== '' ? substr($dueDateRaw, 0, 10) : null,
            $stage
        );

        if (!empty($send['duplicate'])) {
            $stats['duplicates']++;
        } elseif (!empty($send['event_created'])) {
            $stats['created']++;
        } elseif (!empty($send['retry'])) {
            $stats['retried']++;
        }

        if (empty($send['configured'])
            && (int)($send['pending_devices'] ?? 0) > 0) {
            $stats['pending_push']++;
        }

        $stats['sent'] += (int)($send['sent'] ?? 0);
        $stats['failed'] += (int)($send['failed'] ?? 0);
    } catch (Throwable $e) {
        $stats['failed']++;
        fwrite(
            STDERR,
            '[debt notifications] debt ' . (int)$row['debt_id'] . ': ' . $e->getMessage() . "\n"
        );
    }
}

echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
