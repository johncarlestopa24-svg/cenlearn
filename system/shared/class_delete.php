<?php
session_start();
include '../includes/conn.php';
header('Content-Type: application/json');

if(empty($_SESSION['user'])){
    echo json_encode(['success'=>false,'msg'=>'Not logged in']); exit;
}

$user = $_SESSION['user'];
if(strtoupper($user['user_group']) !== 'TEACHER'){
    echo json_encode(['success'=>false,'msg'=>'Unauthorized']); exit;
}

$class_id = intval($_POST['class_id'] ?? 0);
if(!$class_id){
    echo json_encode(['success'=>false,'msg'=>'Invalid class']); exit;
}

$tc = $conn->real_escape_string($user['user_code']);

// Verify teacher owns this class
$q = $conn->query("SELECT id FROM classes WHERE id=$class_id AND teacher_code='$tc'");
if($q->num_rows === 0){
    echo json_encode(['success'=>false,'msg'=>'Class not found or you do not own it']); exit;
}

// Delete uploaded module files from disk
$files = $conn->query("SELECT filename FROM class_modules WHERE class_id=$class_id");
if($files) while($f = $files->fetch_assoc()){
    $path = __DIR__.'/../uploads/modules/'.$f['filename'];
    if(file_exists($path)) @unlink($path);
}

// Delete assignment submission files from disk
$subs = $conn->query("SELECT s.file_name FROM assignment_submissions s JOIN assignments a ON s.assignment_id=a.id WHERE a.class_id=$class_id AND s.file_name IS NOT NULL");
if($subs) while($s = $subs->fetch_assoc()){
    $path = __DIR__.'/../uploads/submissions/'.$s['file_name'];
    if(file_exists($path)) @unlink($path);
}

// Delete all related records
$conn->query("DELETE qs FROM quiz_submissions qs JOIN quiz_questions qq ON qs.quiz_id=qq.quiz_id JOIN quizzes q ON qq.quiz_id=q.id WHERE q.class_id=$class_id");
$conn->query("DELETE qq FROM quiz_questions qq JOIN quizzes q ON qq.quiz_id=q.id WHERE q.class_id=$class_id");
$conn->query("DELETE FROM quizzes WHERE class_id=$class_id");
$conn->query("DELETE s FROM assignment_submissions s JOIN assignments a ON s.assignment_id=a.id WHERE a.class_id=$class_id");
$conn->query("DELETE FROM assignments WHERE class_id=$class_id");
$conn->query("DELETE FROM class_modules  WHERE class_id=$class_id");
$conn->query("DELETE FROM class_members  WHERE class_id=$class_id");
$conn->query("DELETE FROM classes        WHERE id=$class_id AND teacher_code='$tc'");

echo json_encode(['success'=>true,'msg'=>'Class deleted successfully']);
?>
