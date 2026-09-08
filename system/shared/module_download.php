<?php
session_start();
include '../includes/conn.php';

if(empty($_SESSION['user'])){ header('location: index.php'); exit; }
$user = $_SESSION['user'];
$uc   = $conn->real_escape_string($user['user_code']);

// Support both class_modules (id=) and material_repository (repo_id=)
$repo_id = intval($_GET['repo_id'] ?? 0);
$id      = intval($_GET['id']      ?? 0);

if($repo_id){
    // Repository file — teacher only (or any teacher who owns it)
    $q = $conn->query("SELECT * FROM material_repository WHERE id=$repo_id AND teacher_code='$uc'");
    if(!$q || $q->num_rows === 0){ die('File not found or access denied'); }
    $mod = $q->fetch_assoc();
} elseif($id){
    $q = $conn->query("SELECT * FROM class_modules WHERE id=$id");
    if(!$q || $q->num_rows === 0){ die('File not found'); }
    $mod = $q->fetch_assoc();
    // Teachers/Admin can view any module (e.g. from subject repository)
    // Students must be enrolled in the class
    $role = strtoupper($user['role'] ?? $user['user_group'] ?? '');
    if (!in_array($role, ['TEACHER', 'ADMIN', 'SUPERADMIN'])) {
        $cid = intval($mod['class_id']);
        $chk = $conn->query("SELECT id FROM class_members WHERE class_id=$cid AND user_code='$uc'");
        if(!$chk || $chk->num_rows === 0){ die('Access denied'); }
    }
} else {
    die('Invalid request');
}

$filepath = __DIR__.'/../uploads/modules/'.$mod['filename'];
if(!file_exists($filepath)){ die('File not found on server'); }

$viewInline = isset($_GET['view']) && $_GET['view'] === '1';
$ext = strtolower(pathinfo($mod['filename'], PATHINFO_EXTENSION));
$mimeMap = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png'  => 'image/png',  'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'mp4'  => 'video/mp4',  'webm' => 'video/webm',
];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
if($viewInline && $mime !== 'application/octet-stream'){
    header('Content-Disposition: inline; filename="'.addslashes($mod['original_name'] ?? $mod['filename']).'"');
} else {
    header('Content-Description: File Transfer');
    header('Content-Disposition: attachment; filename="'.addslashes($mod['original_name'] ?? $mod['filename']).'"');
}
header('Content-Length: '.filesize($filepath));
header('Pragma: no-cache');
readfile($filepath);
exit;
