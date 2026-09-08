<?php
session_start();
include '../includes/conn.php';
if(empty($_SESSION['user'])){ header('location: ../index.php'); exit; }
$user = $_SESSION['user'];
$uc   = $conn->real_escape_string($user['user_code']);
$id   = intval($_GET['id'] ?? 0);
if(!$id) die('Invalid');

$q = $conn->query("SELECT s.*, a.class_id, a.teacher_code FROM assignment_submissions s JOIN assignments a ON s.assignment_id=a.id WHERE s.id=$id");
if($q->num_rows === 0) die('Not found');
$sub = $q->fetch_assoc();

if($sub['student_code'] !== $user['user_code'] && $sub['teacher_code'] !== $user['user_code']) die('Access denied');
if(!$sub['file_name']) die('No file attached');

$path = __DIR__.'/../uploads/submissions/'.$sub['file_name'];
if(!file_exists($path)) die('File not found on server');

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.addslashes($sub['original_name']).'"');
header('Content-Length: '.filesize($path));
header('Pragma: no-cache');
readfile($path);
exit;
