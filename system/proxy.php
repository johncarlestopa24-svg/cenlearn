<?php
session_start();
header('Content-Type: application/json');

$username  = trim($_POST['txtUserName']  ?? '');
$password  = trim($_POST['txtPassword']  ?? '');
$callback  = trim($_POST['txtCallback']  ?? '');
$requestId = trim($_POST['txtRequestId'] ?? '');

if(!$username || !$password){
    echo json_encode(['is_valid'=>false,'err_msg'=>'MISSING_CREDENTIALS']);
    exit;
}

include 'includes/conn.php';

// ── Helpers ───────────────────────────────────────────────────────────────
function normalizeRole($raw){
    $map = [
        'FACULTY'    => 'TEACHER','INSTRUCTOR' => 'TEACHER',
        'PROFESSOR'  => 'TEACHER','TEACHER'    => 'TEACHER',
        'STUDENT'    => 'STUDENT','ADMIN'      => 'ADMIN',
        'SUPERADMIN' => 'SUPERADMIN',
    ];
    return $map[strtoupper(trim($raw ?? ''))] ?? 'STUDENT';
}

function autoEnrollStudent($conn, $user_code, $program_code, $year_level, $section){
    if(empty($program_code) && !$year_level && empty($section)) return;
    $uc  = $conn->real_escape_string($user_code);
    $pc  = $conn->real_escape_string(strtoupper(trim($program_code)));
    $yl  = intval($year_level);
    $sec = $conn->real_escape_string(strtoupper(trim($section)));
    $where = [];
    if($pc)  $where[] = "(c.program_code='' OR c.program_code IS NULL OR UPPER(c.program_code)='$pc')";
    if($yl)  $where[] = "(c.year_level=0 OR c.year_level IS NULL OR c.year_level=$yl)";
    if($sec) $where[] = "(c.section='' OR c.section IS NULL OR UPPER(c.section)='$sec')";
    $restrict = "((c.program_code!='' AND c.program_code IS NOT NULL) OR (c.year_level!=0 AND c.year_level IS NOT NULL) OR (c.section!='' AND c.section IS NOT NULL))";
    $clause = $restrict . (!empty($where) ? ' AND '.implode(' AND ',$where) : '');
    $res = $conn->query("
        SELECT c.id FROM classes c 
        WHERE $clause 
          AND NOT EXISTS (
              SELECT 1 FROM class_members cm 
              JOIN classes c2 ON cm.class_id = c2.id 
              WHERE cm.user_code = '$uc' 
                AND (cm.class_id = c.id OR UPPER(TRIM(c2.class_name)) = UPPER(TRIM(c.class_name)))
          )
    ");
    if($res) while($cl=$res->fetch_assoc()){
        $cid = intval($cl['id']);
        $conn->query("INSERT IGNORE INTO class_members (class_id,user_code) VALUES ($cid,'$uc')");
        $conn->query("INSERT INTO class_confirmations (class_id, student_code, status, responded_at) VALUES ($cid, '$uc', 'accepted', NOW()) ON DUPLICATE KEY UPDATE status='accepted', responded_at=NOW()");
    }
}

function buildSession($conn, $user_code, $user_group, $data){
    $uc = $conn->real_escape_string($user_code);
    $eq = $conn->query("SELECT user_group, graduated_at, department, program_description, is_active FROM users WHERE user_code='$uc'");
    $localRole = null; $localGrad = null; $localDept = null; $localProgDesc = null; $isActive = true;
    if($eq && $eq->num_rows > 0){
        $er = $eq->fetch_assoc();
        $localRole     = strtoupper($er['user_group']          ?? '');
        $localGrad     = $er['graduated_at'];
        $localDept     = $er['department']          ?? null;
        $localProgDesc = $er['program_description'] ?? null;
        $isActive      = (bool)$er['is_active'];
    }
    if(in_array($localRole, ['SUPERADMIN','ADMIN'])) $user_group = $localRole;

    $graduated_at = $localGrad ?? null;
    if(strtoupper($user_group) === 'STUDENT'){
        $hasProfile = !empty($data['section']) || !empty($data['program_code']) || !empty($data['year_level']);
        if($hasProfile && $graduated_at){
            $conn->query("UPDATE users SET graduated_at=NULL, user_status=NULL WHERE user_code='$uc'");
            $graduated_at = null;
        }
    }

    $token = bin2hex(random_bytes(32));
    $conn->query("UPDATE users SET session_token='$token' WHERE user_code='$uc'");

    $merged = array_merge($data, [
        'is_valid'      => true,
        'user_group'    => $user_group,
        'is_active'     => $isActive,
        'graduated_at'  => $graduated_at,
        'session_token' => $token,
    ]);
    if(in_array($user_group, ['ADMIN','SUPERADMIN'])){
        $merged['department']          = $localDept     ?? ($data['department']          ?? '');
        $merged['program_description'] = $localProgDesc ?? ($data['program_description'] ?? '');
    }
    return $merged;
}

function callTechnoPal($username, $password, $callback, $requestId){
    $ch = curl_init('https://web.bagocitycollege.com/BCCWeb/TPLoginAPI');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'txtUserName'  => $username,
            'txtPassword'  => $password,
            'txtCallback'  => $callback,
            'txtRequestId' => $requestId,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $raw      = curl_exec($ch);
    $curl_err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if($curl_err || $http_code != 200 || !$raw) return null;

    $cleaned = iconv('UTF-8', 'UTF-8//IGNORE', mb_convert_encoding($raw, 'UTF-8', 'UTF-8'));
    $trimmed = trim($cleaned);
    if(!$trimmed || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) return null;

    $decoded = json_decode($trimmed, true);
    return is_array($decoded) ? $decoded : null;
}

function isApiSuccess($data){
    if(!is_array($data)) return false;
    return !empty($data['is_valid'])
        || (isset($data['err_msg']) && strtoupper(trim($data['err_msg'])) === 'TYPE_SUCCESS')
        || !empty($data['login']);
}

function buildDataFromRow($r){
    return [
        'user_code'           => $r['user_code'],
        'first_name'          => $r['first_name']          ?? '',
        'middle_name'         => $r['middle_name']         ?? '',
        'last_name'           => $r['last_name']           ?? '',
        'email_address'       => $r['email_address']       ?? '',
        'cp_number'           => $r['cp_number']           ?? '',
        'address'             => $r['address']             ?? '',
        'gender'              => $r['gender']              ?? '',
        'year_level'          => (int)($r['year_level']    ?? 0),
        'section'             => $r['section']             ?? '',
        'program_code'        => $r['program_code']        ?? '',
        'program_description' => $r['program_description'] ?? '',
        'rfid'                => $r['rfid']                ?? '',
    ];
}

// ── Step 1: Fast-Path Local Verification (Instant Login < 10ms) ───────────
$u_esc     = $conn->real_escape_string($username);
$cache_q   = $conn->query("SELECT * FROM users WHERE user_code='$u_esc' AND is_active=1 LIMIT 1");
$cached_row = null;

if($cache_q && $cache_q->num_rows > 0){
    $cached_row = $cache_q->fetch_assoc();
    if(!empty($cached_row['password_hash']) && password_verify($password, $cached_row['password_hash'])){
        if(!$cached_row['is_active']){
            echo json_encode(['is_valid'=>false,'err_msg'=>'ACCOUNT_DISABLED']); exit;
        }
        $conn->query("UPDATE users SET last_login=NOW() WHERE user_code='$u_esc'");
        $ug      = normalizeRole($cached_row['user_group'] ?? 'STUDENT');
        $session = buildSession($conn, $cached_row['user_code'], $ug, buildDataFromRow($cached_row));
        if($session['user_group'] === 'STUDENT' && empty($session['graduated_at']))
            autoEnrollStudent($conn, $session['user_code'], $session['program_code'], $session['year_level'], $session['section']);
        $_SESSION['user'] = $session;
        echo json_encode($session);
        exit;
    }
}

// ── Step 2: Try TechnoPal if not locally verified ────────────────────────
$api_data = callTechnoPal($username, $password, $callback, $requestId);
$api_ok   = isApiSuccess($api_data);

// ── Step 3: API success — update DB and log in ───────────────────────────
if($api_ok){
    if(empty($api_data['user_code'])) $api_data['user_code'] = $username;

    $uc  = $conn->real_escape_string($api_data['user_code']);
    $fn  = $conn->real_escape_string($api_data['first_name']          ?? '');
    $mn  = $conn->real_escape_string($api_data['middle_name']         ?? '');
    $ln  = $conn->real_escape_string($api_data['last_name']           ?? '');
    $em  = $conn->real_escape_string($api_data['email_address']       ?? '');
    $cp  = $conn->real_escape_string($api_data['cp_number']           ?? '');
    $ad  = $conn->real_escape_string($api_data['address']             ?? '');
    $gn  = $conn->real_escape_string($api_data['gender']              ?? '');
    $yl  = intval($api_data['year_level']                             ?? 0);
    $sec = $conn->real_escape_string($api_data['section']             ?? '');
    $pc  = $conn->real_escape_string($api_data['program_code']        ?? '');
    $pd  = $conn->real_escape_string($api_data['program_description'] ?? '');
    $rf  = $conn->real_escape_string($api_data['rfid']                ?? '');
    $ug  = normalizeRole($api_data['user_group'] ?? '');
    $uge = $conn->real_escape_string($ug);
    $pw  = $conn->real_escape_string(password_hash($password, PASSWORD_DEFAULT));
    $now = date('Y-m-d H:i:s');

    $conn->query("INSERT INTO users
        (user_code,password_hash,first_name,middle_name,last_name,email_address,
         cp_number,address,gender,year_level,section,program_code,program_description,
         rfid,user_group,is_active,last_login,api_cached_at,admin_override)
        VALUES ('$uc','$pw','$fn','$mn','$ln','$em','$cp','$ad','$gn',
                $yl,'$sec','$pc','$pd','$rf','$uge',1,'$now','$now',0)
        ON DUPLICATE KEY UPDATE
            password_hash='$pw', first_name='$fn', middle_name='$mn', last_name='$ln',
            email_address='$em', cp_number='$cp', address='$ad', gender='$gn',
            year_level=IF(admin_override=1, year_level, $yl),
            section=IF(admin_override=1, section, '$sec'),
            program_code='$pc', program_description='$pd', rfid='$rf',
            last_login='$now', api_cached_at='$now',
            user_group=IF(user_group IN ('SUPERADMIN','ADMIN'), user_group, '$uge')");

    // Check is_active (admin may have disabled)
    $ac = $conn->query("SELECT is_active FROM users WHERE user_code='$uc' LIMIT 1");
    if($ac && $ac->num_rows > 0 && !(bool)$ac->fetch_assoc()['is_active']){
        echo json_encode(['is_valid'=>false,'err_msg'=>'ACCOUNT_DISABLED']); exit;
    }

    $session = buildSession($conn, $api_data['user_code'], $ug, buildDataFromRow(array_merge(
        ['user_code'=>$api_data['user_code'],'rfid'=>$api_data['rfid']??''],
        $api_data,
        ['year_level'=>$yl]
    )));
    if($session['user_group'] === 'STUDENT' && empty($session['graduated_at']))
        autoEnrollStudent($conn, $session['user_code'], $session['program_code'], $session['year_level'], $session['section']);
    $_SESSION['user'] = $session;
    echo json_encode($session);
    exit;
}

// ── Step 4: API down/failed — use cached credentials ─────────────────────
if($use_cache && $cached_row){
    if(!$cached_row['is_active']){
        echo json_encode(['is_valid'=>false,'err_msg'=>'ACCOUNT_DISABLED']); exit;
    }
    $conn->query("UPDATE users SET last_login=NOW() WHERE user_code='$u_esc'");
    $ug      = normalizeRole($cached_row['user_group'] ?? 'STUDENT');
    $session = buildSession($conn, $cached_row['user_code'], $ug, buildDataFromRow($cached_row));
    if($session['user_group'] === 'STUDENT' && empty($session['graduated_at']))
        autoEnrollStudent($conn, $session['user_code'], $session['program_code'], $session['year_level'], $session['section']);
    $_SESSION['user'] = $session;
    echo json_encode($session);
    exit;
}

// ── Step 5: Placeholder hash — accept any password when API is down ───────
define('PLACEHOLDER_HASH', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
if($cached_row){
    $isPlaceholder = !empty($cached_row['password_hash'])
                  && password_verify('password', $cached_row['password_hash']);
    if($isPlaceholder){
        $newHash = $conn->real_escape_string(password_hash($password, PASSWORD_DEFAULT));
        $conn->query("UPDATE users SET password_hash='$newHash', api_cached_at=NOW(), last_login=NOW() WHERE user_code='$u_esc'");
        $cached_row['password_hash'] = $newHash;
        $ug      = normalizeRole($cached_row['user_group'] ?? 'STUDENT');
        $session = buildSession($conn, $cached_row['user_code'], $ug, buildDataFromRow($cached_row));
        if($session['user_group'] === 'STUDENT' && empty($session['graduated_at']))
            autoEnrollStudent($conn, $session['user_code'], $session['program_code'], $session['year_level'], $session['section']);
        $_SESSION['user'] = $session;
        echo json_encode($session);
        exit;
    }
    // Wrong password
    echo json_encode(['is_valid'=>false,'err_msg'=>'INVALID_CREDENTIALS']); exit;
}

// ── Step 6: Unknown user — auto-register if looks like a student ID ───────
if(preg_match('/^\d{7,12}$/', $username)){
    $pw_new = $conn->real_escape_string(password_hash($password, PASSWORD_DEFAULT));
    $now_new = date('Y-m-d H:i:s');
    $conn->query("INSERT IGNORE INTO users (user_code,password_hash,user_group,is_active,last_login,api_cached_at)
        VALUES ('$u_esc','$pw_new','STUDENT',1,'$now_new','$now_new')");
    if($conn->affected_rows > 0){
        $session = buildSession($conn, $username, 'STUDENT', buildDataFromRow([
            'user_code'=>$username,'first_name'=>'','middle_name'=>'','last_name'=>'',
            'email_address'=>'','cp_number'=>'','address'=>'','gender'=>'',
            'year_level'=>0,'section'=>'','program_code'=>'','program_description'=>'','rfid'=>'',
        ]));
        $_SESSION['user'] = $session;
        echo json_encode($session);
        exit;
    }
}

echo json_encode(['is_valid'=>false,'err_msg'=>'INVALID_CREDENTIALS']);
