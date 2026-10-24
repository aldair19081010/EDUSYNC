<?php
/**
 * EduSync: dispositivo recordado por 30 días (solo web).
 * Separado de Web Push y del inicio de sesión móvil.
 * La cookie contiene selector + secreto aleatorio, jamás contraseñas.
 * En MySQL se almacena exclusivamente SHA-256 del secreto.
 */
declare(strict_types=1);

const EDRS_COOKIE = 'edusync_remember_session';
const EDRS_TTL = 30 * 24 * 60 * 60;

function edrs_https(): bool {
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function edrs_cookie_allowed(): bool {
    if (edrs_https()) return true;
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = explode(':', $host)[0];
    return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
}

function edrs_cookie_options(int $expiry): array {
    return [
        'expires' => $expiry,
        'path' => '/',
        'secure' => edrs_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function edrs_clear_cookie(): void {
    if (!headers_sent()) setcookie(EDRS_COOKIE, '', edrs_cookie_options(time() - 86400));
    unset($_COOKIE[EDRS_COOKIE]);
}

function edrs_table_ready(mysqli $db): bool {
    try {
        $result = $db->query("SHOW TABLES LIKE 'remembered_login_devices'");
        return $result !== false && $result->num_rows > 0;
    } catch (Throwable $exception) {
        error_log('[EduSync remembered login] table: ' . $exception->getMessage());
        return false;
    }
}

function edrs_fingerprint(string $storedCredential): string {
    // Invalidar dispositivos si se cambia el hash de contraseña en BD.
    // Nunca almacenar ni devolver el hash real de la contraseña.
    return hash('sha256', $storedCredential);
}

function edrs_cookie_parts(): ?array {
    $cookie = (string)($_COOKIE[EDRS_COOKIE] ?? '');
    if (!preg_match('/^([0-9a-f]{24})\.([0-9a-f]{64})$/D', $cookie, $parts)) return null;
    return [$parts[1], $parts[2]];
}

function edrs_revoke(mysqli $db): void {
    $pair = edrs_cookie_parts();
    if ($pair && edrs_table_ready($db)) {
        try {
            $selector = $pair[0];
            $hash = hash('sha256', $pair[1]);
            $stmt = $db->prepare('UPDATE remembered_login_devices SET revoked_at=UTC_TIMESTAMP() WHERE selector=? AND token_hash=?');
            if ($stmt) {
                $stmt->bind_param('ss', $selector, $hash);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $exception) {
            error_log('[EduSync remembered login] revoke: ' . $exception->getMessage());
        }
    }
    edrs_clear_cookie();
}

function edrs_issue(mysqli $db, string $kind, int $school, int $account, string $storedCredential): bool {
    if (!edrs_cookie_allowed() || !edrs_table_ready($db)) return false;
    if (!in_array($kind, ['staff','student'], true) || $school <= 0 || $account <= 0 || $storedCredential === '') return false;
    try {
        // Cerrar la credencial recordada de otra cuenta en este navegador.
        edrs_revoke($db);
        $selector = bin2hex(random_bytes(12));
        $verifier = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $verifier);
        $credentialHash = edrs_fingerprint($storedCredential);
        $created = gmdate('Y-m-d H:i:s');
        $expires = gmdate('Y-m-d H:i:s', time() + EDRS_TTL);
        $stmt = $db->prepare('INSERT INTO remembered_login_devices (selector,token_hash,school_id,account_kind,account_id,credential_hash,created_at,last_used_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?)');
        if (!$stmt) return false;
        $stmt->bind_param('ssisissss', $selector, $tokenHash, $school, $kind, $account, $credentialHash, $created, $created, $expires);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok || headers_sent()) return false;
        setcookie(EDRS_COOKIE, $selector . '.' . $verifier,
            edrs_cookie_options(time() + EDRS_TTL));
        return true;
    } catch (Throwable $exception) {
        error_log('[EduSync remembered login] issue: ' . $exception->getMessage());
        return false;
    }
}

function edrs_process_login(mysqli $db, string $kind, int $school, int $account, string $storedCredential, bool $remember): bool {
    if (!$remember) {
        edrs_revoke($db);
        return false;
    }
    return edrs_issue($db, $kind, $school, $account, $storedCredential);
}

function edrs_has_session(): bool {
    if (!empty($_SESSION['student_logged_in']) && (int)($_SESSION['student_id'] ?? 0)>0
        && (int)($_SESSION['student_school_id'] ?? 0)>0) return true;
    return (int)($_SESSION['login_id'] ?? 0)>0 && (int)($_SESSION['login_school_id'] ?? 0)>0;
}

function edrs_load_staff(mysqli $db, int $school, int $id, string $credentialHash): ?array {
    $statusResult=$db->query("SHOW COLUMNS FROM users LIKE 'status'");
    $statusSelect=($statusResult && $statusResult->num_rows>0)
        ? 'u.status AS current_user_status' : "'Activo' AS current_user_status";
    $sql="SELECT u.*, {$statusSelect}, t.id AS linked_teacher_id, t.status AS teacher_status
          FROM users u
          LEFT JOIN teacher t ON t.id=u.teacher_id AND t.school_id=u.school_id
          WHERE u.id=? AND u.school_id=? LIMIT 1";
    $stmt=$db->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('ii', $id, $school);
    $stmt->execute();
    $row=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !in_array((int)$row['type'],[1,2,3],true)
        || ($row['current_user_status']??'Activo')!=='Activo'
        || !hash_equals($credentialHash,edrs_fingerprint((string)($row['password']??'')))) return null;
    if ((int)$row['type']===2 && ((int)($row['linked_teacher_id']??0)<=0 || ($row['teacher_status']??'Inactivo')!=='Activo')) return null;
    return $row;
}

function edrs_load_student(mysqli $db, int $school, int $id, string $credentialHash): ?array {
    $column=$db->query("SHOW COLUMNS FROM student LIKE 'portal_password_hash'");
    $passwordSelect=($column && $column->num_rows>0)
        ? 'portal_password_hash' : 'NULL AS portal_password_hash';
    $stmt=$db->prepare("SELECT id,name,id_no,{$passwordSelect} FROM student WHERE id=? AND school_id=? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('ii',$id,$school);
    $stmt->execute();
    $row=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $stored = trim((string)($row['portal_password_hash']??''));
    if ($stored==='') $stored='legacy-dni:'.(string)$row['id_no'];
    if (!hash_equals($credentialHash,edrs_fingerprint($stored))) return null;
    return $row;
}

function edrs_restore(mysqli $db): bool {
    if (session_status()!==PHP_SESSION_ACTIVE || edrs_has_session()) return false;
    if (!edrs_cookie_allowed()) return false;
    $pair=edrs_cookie_parts();
    if (!$pair) return false;
    if (!edrs_table_ready($db)) return false;
    try {
        [$selector,$verifier]=$pair;
        $hash=hash('sha256',$verifier);
        $stmt=$db->prepare('SELECT id,token_hash,school_id,account_kind,account_id,credential_hash,expires_at,revoked_at FROM remembered_login_devices WHERE selector=? LIMIT 1');
        if (!$stmt) return false;
        $stmt->bind_param('s',$selector);
        $stmt->execute();
        $saved=$stmt->get_result()->fetch_assoc();
        $stmt->close();
        $expiry=$saved ? strtotime($saved['expires_at'].' UTC') : false;
        if (!$saved || !hash_equals((string)$saved['token_hash'],$hash)
            || $saved['revoked_at']!==null || !$expiry || $expiry<=time()) {
            edrs_clear_cookie();
            return false;
        }
        $school=(int)$saved['school_id'];
        $account=(int)$saved['account_id'];
        $kind=(string)$saved['account_kind'];
        $credentialHash=(string)$saved['credential_hash'];
        $user=$kind==='staff'
            ? edrs_load_staff($db,$school,$account,$credentialHash)
            : ($kind==='student' ? edrs_load_student($db,$school,$account,$credentialHash) : null);
        if (!$user) {
            $stmt=$db->prepare('UPDATE remembered_login_devices SET revoked_at=UTC_TIMESTAMP() WHERE id=?');
            if ($stmt) { $id=(int)$saved['id'];$stmt->bind_param('i',$id);$stmt->execute();$stmt->close(); }
            edrs_clear_cookie();
            return false;
        }
        // Un nuevo verificador por restauración evita reutilizar la cookie anterior.
        $replacement=bin2hex(random_bytes(32));
        $replacementHash=hash('sha256',$replacement);
        $lastUsed=gmdate('Y-m-d H:i:s');
        $stmt=$db->prepare('UPDATE remembered_login_devices SET token_hash=?,last_used_at=? WHERE id=? AND token_hash=? AND revoked_at IS NULL AND expires_at>?');
        if (!$stmt) return false;
        $id=(int)$saved['id'];
        $stmt->bind_param('ssiss',$replacementHash,$lastUsed,$id,$hash,$lastUsed);
        $stmt->execute();
        $changed=$stmt->affected_rows===1;
        $stmt->close();
        if (!$changed) {edrs_clear_cookie();return false;}
        session_regenerate_id(true);
        $_SESSION=[];
        if ($kind==='staff') {
            foreach ($user as $key=>$value) {
                if (!in_array($key,['password','linked_teacher_id','teacher_status','current_user_status'],true)
                    && !is_numeric($key)) $_SESSION['login_'.$key]=$value;
            }
            $_SESSION['login_id']=(int)$user['id'];
            $_SESSION['login_school_id']=$school;
            $_SESSION['login_type']=(int)$user['type'];
            $_SESSION['user_name']=(string)$user['name'];
            if ((int)$user['type']===2) $_SESSION['login_teacher_id']=(int)$user['teacher_id'];
            if (!empty($user['avatar'])) $_SESSION['login_avatar']=$user['avatar'];
        } else {
            $_SESSION['student_logged_in']=true;
            $_SESSION['login_type']=4;
            $_SESSION['student_id']=$account;
            $_SESSION['student_name']=(string)$user['name'];
            $_SESSION['student_dni']=(string)$user['id_no'];
            $_SESSION['student_school_id']=$school;
            $_SESSION['login_name']=(string)$user['name'];
            $_SESSION['login_school_id']=$school;
        }
        // Recuperar la configuración global de la sesión que login.php
        // carga normalmente al iniciar con contraseña.
        try {
            $settings = $db->query('SELECT * FROM system_settings LIMIT 1');
            if ($settings && $system = $settings->fetch_assoc()) {
                $_SESSION['system'] = $system;
            }
        } catch (Throwable $ignored) { /* La configuración no impide el acceso. */ }
        $_SESSION['csrf_token']=bin2hex(random_bytes(32));
        setcookie(EDRS_COOKIE,$selector.'.'.$replacement,edrs_cookie_options($expiry));
        // Sincronizar la cookie también durante esta petición PHP.
        // Permite revocar inmediatamente si la misma petición cierra sesión.
        $_COOKIE[EDRS_COOKIE]=$selector.'.'.$replacement;
        return true;
    } catch (Throwable $exception) {
        error_log('[EduSync remembered login] restore: ' . $exception->getMessage());
        return false;
    }
}
