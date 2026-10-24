<?php
declare(strict_types=1);

// Página de emisión privada: nunca emite credenciales a Auxiliar o estudiante.
require_once __DIR__.'/session_config.php';
require_once __DIR__.'/db_connect.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');

$userId = (int)($_SESSION['login_id'] ?? 0);
$school = (int)($_SESSION['login_school_id'] ?? 0);
if ($userId <= 0 || $school <= 0 || (int)($_SESSION['login_type'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Solo Administración puede emitir o revocar carnés QR.');
}
$allowed = $conn->prepare("SELECT id FROM users
    WHERE id=? AND school_id=? AND type=1 LIMIT 1");
$allowed->bind_param('ii', $userId, $school);
$allowed->execute();
$validAdmin = (bool)$allowed->get_result()->fetch_assoc();
$allowed->close();
if (!$validAdmin) {
    http_response_code(403);
    exit('Cuenta administrativa no autorizada.');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($id < 1) {
    http_response_code(422);
    exit('Identificador de estudiante inválido.');
}
$stmt = $conn->prepare("SELECT id,name,nivel,grado,seccion,status
    FROM student WHERE id=? AND school_id=? LIMIT 1");
$stmt->bind_param('ii', $id, $school);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$student) {
    http_response_code(404);
    exit('Estudiante no encontrado en esta institución.');
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['csrf_token'];
$qrContent = '';
$feedback = '';
$error = '';
$tableCheck = $conn->query("SHOW TABLES LIKE 'student_attendance_qr'");
$installed = $tableCheck && $tableCheck->num_rows > 0;
if (!$installed) {
    $error = 'Primero debes aplicar sql_student_attendance_qr.sql en la base de datos.';
}
$activeStudent = ($student['status'] === 'Activo');
if (!$activeStudent) {
    $error = 'Solo se emiten carnés QR para estudiantes activos.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $installed && $activeStudent) {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf, $postedToken)) {
        http_response_code(403);
        $error = 'El formulario de seguridad expiró. Recarga la página.';
    } else {
        $operation = (string)($_POST['operation'] ?? '');
        try {
            if ($operation === 'rotate') {
                $rawToken = bin2hex(random_bytes(24));
                $tokenHash = hash('sha256', $rawToken);
                $stmt = $conn->prepare("INSERT INTO student_attendance_qr
                    (school_id,student_id,token_hash,issued_by)
                    VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                      token_hash=VALUES(token_hash),
                      issued_by=VALUES(issued_by), updated_at=CURRENT_TIMESTAMP");
                if (!$stmt) throw new RuntimeException('No se pudo preparar la emisión.');
                $stmt->bind_param('iisi', $school, $id, $tokenHash, $userId);
                if (!$stmt->execute()) throw new RuntimeException('No se pudo emitir.');
                $stmt->close();
                $qrContent = 'EDUQR1-'.$rawToken;
                $feedback = 'QR emitido. Imprímelo ahora: por seguridad el código no se guarda en texto y no se puede recuperar después.';
            } elseif ($operation === 'revoke') {
                $stmt = $conn->prepare("DELETE FROM student_attendance_qr
                    WHERE school_id=? AND student_id=?");
                $stmt->bind_param('ii', $school, $id);
                if (!$stmt->execute()) throw new RuntimeException('No se pudo revocar.');
                $stmt->close();
                $feedback = 'QR revocado. Ya no permitirá nuevas marcaciones.';
            } else {
                http_response_code(422);
                $error = 'Operación no permitida.';
            }
        } catch (Throwable $e) {
            error_log('[student attendance qr] '.$e->getMessage());
            $qrContent = '';
            $error = 'No se pudo completar la operación. Revisa el servidor.';
        }
    }
}
$hasQr = false;
if ($installed) {
    $stmt = $conn->prepare("SELECT student_id FROM student_attendance_qr
        WHERE school_id=? AND student_id=? LIMIT 1");
    $stmt->bind_param('ii',$school,$id);
    $stmt->execute();
    $hasQr = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
}
function qr_esc($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Carné QR EduSync</title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:24px 12px;background:#f1f5fb;color:#172b4a;font:15px system-ui,-apple-system,sans-serif}
main{max-width:590px;margin:auto}
.card{background:#fff;border:1px solid #dce5f0;border-radius:17px;padding:22px;box-shadow:0 8px 27px #1a365b12}
h1{margin:0 0 5px;font-size:22px;color:#1554a8}h2{font-size:17px}
.sub{color:#657791;font-size:13px;margin:0 0 17px}
.summary{padding:13px;background:#f3f8ff;border-radius:12px;margin-bottom:16px}
.summary strong{display:block;font-size:16px}.summary small{color:#587189}
.notice{padding:12px;border-radius:11px;margin:13px 0;background:#e6f7ee;color:#176345}
.error{background:#ffecea;color:#922d24}
.actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:18px}
button{cursor:pointer;border:0;border-radius:10px;padding:11px 15px;font-weight:700;background:#1456aa;color:#fff}
button.secondary{background:#e8eef7;color:#1b3d69}
button.danger{background:#fff1ef;color:#a03830;border:1px solid #eab7b0}
button:disabled{opacity:.5;cursor:default}
form{display:inline}
#credential{display:none;text-align:center;padding:13px 0 2px}
#qr{display:flex;justify-content:center;padding:13px;background:white}
#qr img,#qr canvas{max-width:100%;height:auto}
.note{font-size:12px;color:#61748d;line-height:1.5}
@media print{body{padding:0;background:#fff}main{max-width:none}.card{border:0;box-shadow:none}
.actions,.notice,.error,.intro,.note{display:none!important}#credential{display:block!important}}
</style>
</head>
<body>
<main>
<section class="card">
<h1>EduSync · Carné QR</h1>
<p class="sub intro">Emisión controlada por Administración · <?= qr_esc(date('d/m/Y')) ?></p>
<div class="summary">
<strong><?= qr_esc($student['name']) ?></strong>
<small><?= qr_esc($student['nivel']) ?> · <?= qr_esc($student['grado']) ?> · <?= qr_esc($student['seccion']) ?></small>
</div>
<?php if ($feedback !== ''): ?><div class="notice"><?= qr_esc($feedback) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error"><?= qr_esc($error) ?></div><?php endif; ?>
<?php if ($qrContent !== ''): ?>
<div id="credential">
<h2>Carné de asistencia</h2>
<div id="qr" role="img" aria-label="Código QR de asistencia EduSync"></div>
<p class="sub">Presenta este código al auxiliar para Entrada y Salida.</p>
</div>
<?php endif; ?>
<div class="intro">
<p class="note">El QR no contiene DNI, nombres ni datos académicos. El servidor verifica que el estudiante esté activo y pertenezca a este colegio.</p>
<p class="note"><?= $hasQr ? 'Ya existe un QR activo; emitir uno nuevo invalida el anterior.' : 'Este estudiante todavía no tiene un QR activo.' ?></p>
</div>
<div class="actions">
<?php if ($installed && $activeStudent): ?>
<form method="post" onsubmit="return confirm('¿Emitir un QR nuevo? El anterior dejará de funcionar.');">
<input type="hidden" name="csrf_token" value="<?= qr_esc($csrf) ?>">
<input type="hidden" name="operation" value="rotate">
<button type="submit"><?= $hasQr ? 'Reemplazar QR' : 'Generar QR' ?></button>
</form>
<?php if ($hasQr): ?>
<form method="post" onsubmit="return confirm('¿Revocar el QR? No podrá usarse para marcar asistencia.');">
<input type="hidden" name="csrf_token" value="<?= qr_esc($csrf) ?>">
<input type="hidden" name="operation" value="revoke">
<button class="danger" type="submit">Revocar</button>
</form>
<?php endif; ?>
<?php endif; ?>
<?php if ($qrContent !== ''): ?><button type="button" id="print-card" class="secondary" disabled>Imprimir carné</button><?php endif; ?>
<button type="button" class="secondary" onclick="window.close()">Cerrar</button>
</div>
<p class="note">Guarda el carné impreso. Si se pierde, reemplaza su QR; una fotografía del código puede ser copiada, por lo que el auxiliar debe comprobar la identidad cuando corresponda.</p>
</section>
</main>
<?php if ($qrContent !== ''): ?>
<script src="js/vendor/qrcode.min.js"></script>
<script>
(function(){
  var node = document.getElementById('qr');
  var printButton = document.getElementById('print-card');
  try {
    if (typeof QRCode !== 'function') throw Error('Sin biblioteca QR');
    new QRCode(node, {
      text: <?= json_encode($qrContent, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      width: 225,
      height: 225,
      correctLevel: QRCode.CorrectLevel.M
    });
    printButton.disabled = false;
    printButton.addEventListener('click', function(){window.print();});
    document.getElementById('credential').style.display = 'block';
  } catch (err) {
    var notice = document.createElement('p');
    notice.className = 'notice error';
    notice.textContent = 'No se pudo dibujar el QR. Comprueba que js/vendor/qrcode.min.js esté publicado.';
    node.appendChild(notice);
  }
})();
</script>
<?php endif; ?>
</body>
</html>
