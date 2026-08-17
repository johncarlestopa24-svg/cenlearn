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
    if($q->num_rows === 0){ die('File not found'); }
    $mod = $q->fetch_assoc();
    // Check user is a member of this class
    $cid = intval($mod['class_id']);
    $chk = $conn->query("SELECT id FROM class_members WHERE class_id=$cid AND user_code='$uc'");
    if($chk->num_rows === 0){ die('Access denied'); }
} else {
    die('Invalid request');
}

$filepath = __DIR__.'/../uploads/modules/'.$mod['filename'];
if(!file_exists($filepath)){ die('File not found on server'); }

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.addslashes($mod['original_name']).'"');
header('Content-Length: '.filesize($filepath));
header('Pragma: no-cache');
readfile($filepath);
exit;
