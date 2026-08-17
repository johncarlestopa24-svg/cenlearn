<?php
session_start();
include '../includes/conn.php';
header('Content-Type: application/json');

if(empty($_SESSION['user']) || !in_array($_SESSION['user']['user_group'], ['ADMIN','SUPERADMIN'])){
    echo json_encode(['error'=>'Unauthorized']); exit;
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);
if(!is_array($data)){ echo json_encode(['error'=>'Invalid payload']); exit; }

$updated = 0;
$now = date('Y-m-d H:i:s');

foreach($data as $user_code => $api){
    $uc  = $conn->real_escape_string($user_code);
    $yl  = intval($api['year_level'] ?? 0);
    $sec = $conn->real_escape_string($api['section'] ?? '');
    $pc  = $conn->real_escape_string($api['program_code'] ?? '');
    $pd  = $conn->real_escape_string($api['program_description'] ?? '');

    if(!$yl && !$sec && !$pc) continue; // skip if API returned nothing useful

    $res = $conn->query("UPDATE users
        SET year_level=$yl,
            section='$sec',
            program_code=IF('$pc'!='', '$pc', program_code),
            program_description=IF('$pd'!='', '$pd', program_description),
            api_cached_at='$now'
        WHERE user_code='$uc' AND user_group='STUDENT'");

    if($res && $conn->affected_rows >= 0) $updated++;
}

echo json_encode(['success'=>true, 'updated'=>$updated]);
