<?php
// TEMPORARY DEBUG FILE — DELETE AFTER USE
// Only accessible from localhost
if(!in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1','::1'])){
    http_response_code(403); die('Forbidden');
}

header('Content-Type: application/json');
include 'includes/conn.php';

$user_code = trim($_GET['uc'] ?? '');
if(!$user_code){ echo json_encode(['error'=>'Pass ?uc=STUDENT_ID']); exit; }

$uc = $conn->real_escape_string($user_code);

// 1. Check what's in DB
$q = $conn->query("SELECT user_code, first_name, last_name, year_level, section, program_code, is_active, admin_override,
    CASE WHEN password_hash IS NULL OR password_hash='' THEN 'NO_HASH'
         WHEN password_hash='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' THEN 'PLACEHOLDER'
         ELSE 'HAS_HASH'
    END as hash_status,
    api_cached_at
    FROM users WHERE user_code='$uc' LIMIT 1");

$db = ($q && $q->num_rows > 0) ? $q->fetch_assoc() : null;

// 2. Test API reachability (no credentials)
$ch = curl_init('https://web.bagocitycollege.com/BCCWeb/TPLoginAPI');
curl_setopt_array($ch,[
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>http_build_query(['txtUserName'=>$user_code,'txtPassword'=>'test123']),
    CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,
    CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_FOLLOWLOCATION=>false,
    CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>8,
    CURLOPT_USERAGENT=>'Mozilla/5.0',
]);
$raw  = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$api_response = null;
$api_preview  = substr($raw, 0, 200);
if($raw && $code==200){
    $t = trim(iconv('UTF-8','UTF-8//IGNORE',mb_convert_encoding($raw,'UTF-8','UTF-8')));
    if($t && ($t[0]==='{' || $t[0]==='[')) $api_response = json_decode($t, true);
}

echo json_encode([
    'db'           => $db,
    'api_http'     => $code,
    'api_curl_err' => $err ?: null,
    'api_parsed'   => $api_response,
    'api_raw_preview' => $api_preview,
], JSON_PRETTY_PRINT);
