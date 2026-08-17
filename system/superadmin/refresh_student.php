<?php
if(session_status() === PHP_SESSION_NONE) session_start();
include '../includes/conn.php';

header('Content-Type: application/json');

if(empty($_SESSION['user']) || $_SESSION['user']['user_group'] !== 'SUPERADMIN'){
    echo json_encode(['success'=>false,'msg'=>'Unauthorized']); exit;
}

$user_code = trim($_POST['user_code'] ?? '');
if(empty($user_code)){
    echo json_encode(['success'=>false,'msg'=>'User code required']); exit;
}

// Try TechnoPal with correct URL
$ch = curl_init('https://web.bagocitycollege.com/BCCWeb/TPLoginAPI');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query(['txtUserName'=>$user_code,'txtPassword'=>'']),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_USERAGENT      => 'Mozilla/5.0',
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);
$raw      = curl_exec($ch);
$curl_err = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$api_data = null;
if(!$curl_err && $http_code == 200 && $raw){
    $cleaned  = iconv('UTF-8','UTF-8//IGNORE', mb_convert_encoding($raw,'UTF-8','UTF-8'));
    $trimmed  = trim($cleaned);
    // Only accept valid JSON with actual profile fields
    if($trimmed && ($trimmed[0]==='{' || $trimmed[0]==='[')){
        $decoded = json_decode($trimmed, true);
        if(is_array($decoded) && !empty($decoded['program_code'])){
            $api_data = $decoded;
        }
    }
}

$uc = $conn->real_escape_string($user_code);

if($api_data){
    // API returned valid data — update DB
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
    $now = date('Y-m-d H:i:s');

    $conn->query("UPDATE users
        SET first_name='$fn', middle_name='$mn', last_name='$ln', email_address='$em',
            cp_number='$cp', address='$ad', gender='$gn', year_level=$yl, section='$sec',
            program_code='$pc', program_description='$pd', rfid='$rf',
            api_cached_at='$now', admin_override=0
        WHERE user_code='$uc'");

    // Re-read from DB
    $uq = $conn->query("SELECT * FROM users WHERE user_code='$uc' LIMIT 1");
    $row = $uq ? $uq->fetch_assoc() : [];

    echo json_encode([
        'success' => true,
        'source'  => 'api',
        'msg'     => 'Refreshed from TechnoPal.',
        'data'    => [
            'first_name'   => $row['first_name']   ?? $fn,
            'last_name'    => $row['last_name']    ?? $ln,
            'program_code' => $row['program_code'] ?? $pc,
            'year_level'   => $row['year_level']   ?? $yl,
            'section'      => $row['section']      ?? $sec,
        ]
    ]);

} else {
    // API unavailable — return current DB values so the UI can still update
    $uq  = $conn->query("SELECT first_name, last_name, program_code, year_level, section FROM users WHERE user_code='$uc' LIMIT 1");
    $row = ($uq && $uq->num_rows > 0) ? $uq->fetch_assoc() : null;

    if(!$row){
        echo json_encode(['success'=>false,'msg'=>'Student not found.']); exit;
    }

    echo json_encode([
        'success' => true,
        'source'  => 'db',
        'msg'     => 'TechnoPal unavailable — showing current DB data. Use Edit to update manually.',
        'data'    => [
            'first_name'   => $row['first_name'],
            'last_name'    => $row['last_name'],
            'program_code' => $row['program_code'],
            'year_level'   => $row['year_level'],
            'section'      => $row['section'],
        ]
    ]);
}
