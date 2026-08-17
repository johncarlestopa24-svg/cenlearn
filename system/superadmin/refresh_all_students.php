<?php
if(session_status() === PHP_SESSION_NONE) session_start();
include '../includes/conn.php';

header('Content-Type: application/json');

if(empty($_SESSION['user']) || $_SESSION['user']['user_group'] !== 'SUPERADMIN'){
    echo json_encode(['success'=>false,'msg'=>'Unauthorized']); exit;
}

$q = $conn->query("SELECT user_code FROM users WHERE user_group='STUDENT' AND is_active=1");
if (!$q) {
    echo json_encode(['success' => false, 'msg' => 'DB error: ' . $conn->error]);
    exit;
}

$updated = 0;
$failed  = 0;
$skipped = 0;

while ($row = $q->fetch_assoc()) {
    $user_code = $row['user_code'];

    $ch = curl_init('https://web.bagocitycollege.com/BCCWeb/TPLoginAPI');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'txtUserName' => $user_code,
            'txtPassword' => '',
        ]),
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

    if ($curl_err || $http_code != 200 || !$raw) {
        $skipped++;
        continue;
    }

    $cleaned  = iconv('UTF-8', 'UTF-8//IGNORE', mb_convert_encoding($raw, 'UTF-8', 'UTF-8'));
    $trimmed  = trim($cleaned);
    if (!$trimmed || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
        $skipped++;
        continue;
    }

    $api = json_decode($trimmed, true);
    if (!is_array($api)) {
        $skipped++;
        continue;
    }

    // Only update if API returned meaningful profile fields
    $yl  = intval($api['year_level']    ?? 0);
    $sec = $conn->real_escape_string($api['section']             ?? '');
    $pc  = $conn->real_escape_string($api['program_code']        ?? '');
    $pd  = $conn->real_escape_string($api['program_description'] ?? '');
    $fn  = $conn->real_escape_string($api['first_name']          ?? '');
    $mn  = $conn->real_escape_string($api['middle_name']         ?? '');
    $ln  = $conn->real_escape_string($api['last_name']           ?? '');
    $em  = $conn->real_escape_string($api['email_address']       ?? '');
    $cp  = $conn->real_escape_string($api['cp_number']           ?? '');
    $ad  = $conn->real_escape_string($api['address']             ?? '');
    $gn  = $conn->real_escape_string($api['gender']              ?? '');
    $rf  = $conn->real_escape_string($api['rfid']                ?? '');
    $uc  = $conn->real_escape_string($user_code);
    $now = date('Y-m-d H:i:s');

    $res = $conn->query("UPDATE users
        SET year_level=$yl, section='$sec', program_code='$pc', program_description='$pd',
            first_name='$fn', middle_name='$mn', last_name='$ln', email_address='$em',
            cp_number='$cp', address='$ad', gender='$gn', rfid='$rf', api_cached_at='$now'
        WHERE user_code='$uc'");

    if ($res) $updated++; else $failed++;
}

echo json_encode([
    'success' => true,
    'updated' => $updated,
    'skipped' => $skipped,
    'failed'  => $failed,
    'msg'     => "Done: $updated updated, $skipped skipped (API no data), $failed failed."
]);
