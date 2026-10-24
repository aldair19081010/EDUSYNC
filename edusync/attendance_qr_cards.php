<?php
declare(strict_types=1);

// Impresión de carnés QR: uso interno y exclusivo de Administración.
ini_set('display_errors', '0');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
require_once __DIR__.'/session_config.php';
require_once __DIR__.'/db_connect.php';
require_once __DIR__.'/includes/attendance_qr_code.php';

$school = (int)($_SESSION['login_school_id'] ?? 0);
$userId = (int)($_SESSION['login_id'] ?? 0);
if ($school < 1 || $userId < 1 || (int)($_SESSION['login_type'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Solo Administración puede imprimir carnés QR.');
}
$activeColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
$active = $activeColumn && $activeColumn->num_rows > 0 ? " AND status='Activo'" : '';
$u = $conn->prepare("SELECT id FROM users WHERE id=? AND school_id=? AND type=1 {$active} LIMIT 1");
$u->bind_param('ii', $userId, $school);
$u->execute();
$allowed = (bool)$u->get_result()->fetch_assoc();
$u->close();
if (!$allowed) { http_response_code(403); exit('Cuenta no autorizada.'); }

function qr_cards_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
$nivel = trim((string)($_GET['nivel'] ?? ''));
$grado = trim((string)($_GET['grado'] ?? ''));
$seccion = trim((string)($_GET['seccion'] ?? ''));
if (strlen($nivel) > 30 || strlen($grado) > 20 || strlen($seccion) > 10) {
    http_response_code(422);
    exit('Filtros demasiado largos.');
}

$schoolStmt = $conn->prepare('SELECT name FROM schools WHERE id=? LIMIT 1');
$schoolStmt->bind_param('i', $school);
$schoolStmt->execute();
$schoolName = (string)($schoolStmt->get_result()->fetch_assoc()['name'] ?? 'Institución educativa');
$schoolStmt->close();

$qrCodes = [];
$message = '';
try {
    // El colegio viene exclusivamente de la sesión. Máximo 200 carnés por
    // hoja/lote para que imprimir no bloquee el navegador.
    $q = $conn->prepare("SELECT id,name,nivel,grado,seccion FROM student
        WHERE school_id=? AND status='Activo'
          AND (?='' OR nivel=?) AND (?='' OR grado=?)
          AND (?='' OR seccion=?)
        ORDER BY nivel,grado,seccion,name,id LIMIT 201");
    $q->bind_param('issssss', $school, $nivel, $nivel,
        $grado, $grado, $seccion, $seccion);
    $q->execute();
    $students = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();
    if (count($students) > 200) {
        $students = [];
        $message = 'Hay más de 200 estudiantes. Filtra por nivel, grado o sección para imprimir por lotes.';
    } else {
        foreach ($students as $student) {
            $qrCodes[] = [
                'id'=>(int)$student['id'],
                'name'=>(string)$student['name'],
                'level'=>(string)$student['nivel'],
                'grade'=>(string)$student['grado'],
                'section'=>(string)$student['seccion'],
                'code'=>edusync_attendance_qr_issue($school, (int)$student['id']),
            ];
        }
    }
} catch (RuntimeException $e) {
    $qrCodes = [];
    $message = 'No está configurada la clave privada de QR en el hosting.';
} catch (Throwable $e) {
    error_log('[QR print] '.$e->getMessage());
    $qrCodes = [];
    $message = 'No se pudieron preparar los carnés.';
}
$jsCodes = json_encode($qrCodes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG |
    JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$nonce}' https://cdnjs.cloudflare.com; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Carnés QR · EduSync</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f1f5fb;color:#21344d;font-family:Arial,sans-serif}
.toolbar{max-width:1050px;margin:20px auto;background:white;border-radius:14px;padding:20px;box-shadow:0 3px 15px #12345712}
h1{font-size:21px;margin:0 0 6px;color:#1155ae}p{font-size:13px;line-height:1.5}
.controls{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:14px 0}
label{font-size:12px;font-weight:bold;display:flex;flex-direction:column;gap:5px}
input{padding:10px;border:1px solid #bdc9d9;border-radius:8px;min-width:100px}
button{background:#1155ae;color:white;border:0;border-radius:8px;padding:11px 18px;font-weight:bold;cursor:pointer}
.secondary{background:#e9f1ff;color:#1155ae}.notice{color:#b44627;font-weight:bold}
.sheet{max-width:1040px;margin:12px auto;display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.card{min-width:0;background:white;border:1px solid #cbd6e5;border-radius:12px;padding:12px;break-inside:avoid;page-break-inside:avoid}
.brand{font-weight:900;color:#1254ad;letter-spacing:.5px;font-size:11px}
.school{font-weight:700;font-size:10px;min-height:24px;margin:4px 0}
.student{font-weight:800;font-size:12px;line-height:1.3;min-height:31px;overflow-wrap:anywhere}
.details{font-size:11px;margin:4px 0 8px;color:#49617d}
.qr{height:128px;display:flex;align-items:center;justify-content:center}.qr img{width:128px;height:128px}
.meta{font-size:9px;color:#6a7a8f;text-align:center;margin-top:5px}
@media print{body{background:white}.toolbar{display:none}.sheet{max-width:none;margin:0;gap:8px}
.card{border:1px solid #aaa;border-radius:4px;padding:9px;min-height:228px}
@page{size:A4;margin:9mm}}
@media(max-width:740px){.sheet{grid-template-columns:repeat(2,1fr);padding:12px}}
@media(max-width:470px){.sheet{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="toolbar">
<h1>Carnés QR · EduSync</h1>
<p><strong><?=qr_cards_h($schoolName)?></strong> · Emisión exclusiva de Administración.
Cada código permite identificar al estudiante sin incluir su DNI o nombre en el QR.</p>
<form class="controls" method="get" action="attendance_qr_cards.php">
<label>Nivel<input name="nivel" placeholder="Todos" value="<?=qr_cards_h($nivel)?>"></label>
<label>Grado<input name="grado" placeholder="Todos" value="<?=qr_cards_h($grado)?>"></label>
<label>Sección<input name="seccion" placeholder="Todas" value="<?=qr_cards_h($seccion)?>"></label>
<button type="submit">Consultar</button>
<button type="button" id="print" class="secondary">Imprimir carnés</button>
</form>
<p><?=count($qrCodes)?> carnés preparados. Imprime en A4 y verifica que el QR sea legible antes de repartirlos.</p>
<?php if ($message !== ''): ?><p class="notice"><?=qr_cards_h($message)?></p><?php endif; ?>
<p class="notice" id="script-error" hidden>No se pudo cargar el generador de QR. Verifica la conexión antes de imprimir.</p>
</div>
<div class="sheet">
<?php foreach($qrCodes as $student): ?>
<article class="card">
<div class="brand">EDUSYNC · CARNÉ ESTUDIANTIL</div>
<div class="school"><?=qr_cards_h($schoolName)?></div>
<div class="student"><?=qr_cards_h($student['name'])?></div>
<div class="details"><?=qr_cards_h($student['level'].' · '.$student['grade'].' · '.$student['section'])?></div>
<div class="qr" id="qr-<?= (int)$student['id'] ?>"></div>
<div class="meta">QR institucional · Presentar para Entrada y Salida</div>
</article>
<?php endforeach; ?>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"
 integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA=="
 crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script nonce="<?=qr_cards_h($nonce)?>">
(function(){
  'use strict';
  const students = <?=$jsCodes ?: '[]'?>;
  const button = document.getElementById('print');
  if (typeof QRCode !== 'function') {
    document.getElementById('script-error').hidden = false;
    button.disabled = true;
    return;
  }
  students.forEach(function(student) {
    const node = document.getElementById('qr-' + student.id);
    if (!node) return;
    new QRCode(node, {
      text: student.code, width: 128, height: 128,
      colorDark: '#142d51', colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.M
    });
  });
  button.addEventListener('click', function(){ window.print(); });
})();
</script>
</body>
</html>
