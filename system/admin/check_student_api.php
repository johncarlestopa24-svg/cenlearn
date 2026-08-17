<?php
session_start();
include '../includes/conn.php';

if(empty($_SESSION['user']) || !in_array($_SESSION['user']['user_group'], ['ADMIN','SUPERADMIN'])){
    echo json_encode(['error' => 'Unauthorized']); exit;
}

$user_code = trim($_GET['user_code'] ?? '');
if(!$user_code){ echo json_encode(['error' => 'user_code required']); exit; }

// What's in the DB right now
$uc = $conn->real_escape_string($user_code);
$dbq = $conn->query("SELECT user_code, first_name, last_name, year_level, section, program_code, api_cached_at FROM users WHERE user_code='$uc'");
$db_data = $dbq ? $dbq->fetch_assoc() : null;

// What TechnoPal returns right now
$ch = curl_init('https://web.bagocitycollege.com/BCCWeb/TPLoginAPI');
curl_setopt_array($ch,[
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>http_build_query(['txtUserName'=>$user_code,'txtPassword'=>'']),
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_SSL_VERIFYPEER=>false,
    CURLOPT_SSL_VERIFYHOST=>false,
    CURLOPT_FOLLOWLOCATION=>false,
    CURLOPT_CONNECTTIMEOUT=>5,
    CURLOPT_TIMEOUT=>8,
    CURLOPT_USERAGENT=>'Mozilla/5.0',
]);
$raw = curl_exec($ch);
$curl_err = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$api_data = null;
$api_raw_preview = substr($raw, 0, 300);
if($raw && $http_code == 200){
    $cleaned = iconv('UTF-8','UTF-8//IGNORE', mb_convert_encoding($raw,'UTF-8','UTF-8'));
    $trimmed = trim($cleaned);
    if($trimmed && ($trimmed[0]==='{' || $trimmed[0]==='[')){
        $api_data = json_decode($trimmed, true);
    }
}

header('Content-Type: application/json');
echo json_encode([
    'db'  => $db_data,
    'api' => $api_data ? [
        'year_level'          => $api_data['year_level'] ?? 'NOT_RETURNED',
        'section'             => $api_data['section'] ?? 'NOT_RETURNED',
        'program_code'        => $api_data['program_code'] ?? 'NOT_RETURNED',
        'program_description' => $api_data['program_description'] ?? 'NOT_RETURNED',
        'first_name'          => $api_data['first_name'] ?? '',
        'last_name'           => $api_data['last_name'] ?? '',
        'is_valid'            => $api_data['is_valid'] ?? null,
        'err_msg'             => $api_data['err_msg'] ?? null,
    ] : null,
    'api_http_code' => $http_code,
    'api_curl_error'=> $curl_err ?: null,
    'api_raw_preview' => $api_raw_preview,
], JSON_PRETTY_PRINT);
