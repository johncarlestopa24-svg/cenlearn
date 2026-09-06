<?php
if(session_status() === PHP_SESSION_NONE) session_start();
include_once __DIR__ . '/../includes/conn.php';
header('Content-Type: application/json');

if(empty($_SESSION['user'])){
    echo json_encode(['success'=>false,'msg'=>'Not logged in']); exit;
}

$user = $_SESSION['user'];
$role = strtoupper($user['user_group'] ?? '');
if(!in_array($role, ['TEACHER', 'ADMIN', 'SUPERADMIN'])){
    echo json_encode(['success'=>false,'msg'=>'Unauthorized']); exit;
}

$class_id = intval($_POST['class_id'] ?? 0);
if(!$class_id){
    echo json_encode(['success'=>false,'msg'=>'Invalid class']); exit;
}

$tc = $conn->real_escape_string($user['user_code']);

// Verify teacher owns this class (or is admin)
if($role === 'TEACHER'){
    $q = $conn->query("SELECT id, class_name, subject, class_code FROM classes WHERE id=$class_id AND teacher_code='$tc'");
    if(!$q || $q->num_rows === 0){
        echo json_encode(['success'=>false,'msg'=>'Class not found or you do not own it']); exit;
    }
} else {
    $q = $conn->query("SELECT id, class_name, subject, class_code FROM classes WHERE id=$class_id");
    if(!$q || $q->num_rows === 0){
        echo json_encode(['success'=>false,'msg'=>'Class not found']); exit;
    }
}

// 1. Delete uploaded module files from disk
$files = $conn->query("SELECT filename FROM class_modules WHERE class_id=$class_id");
if($files) while($f = $files->fetch_assoc()){
    if(!empty($f['filename'])){
        $path = __DIR__.'/../uploads/modules/'.$f['filename'];
        if(file_exists($path)) @unlink($path);
    }
}

// 2. Delete assignment submission files from disk
$subs = $conn->query("SELECT s.file_name FROM assignment_submissions s JOIN assignments a ON s.assignment_id=a.id WHERE a.class_id=$class_id AND s.file_name IS NOT NULL");
if($subs) while($s = $subs->fetch_assoc()){
    if(!empty($s['file_name'])){
        $path = __DIR__.'/../uploads/submissions/'.$s['file_name'];
        if(file_exists($path)) @unlink($path);
    }
}

// 3. Delete all related records across all tables in proper cascading order
// Quizzes & Submissions
$conn->query("DELETE qa FROM quiz_attempts qa JOIN quizzes q ON qa.quiz_id=q.id WHERE q.class_id=$class_id");
$conn->query("DELETE qs FROM quiz_submissions qs JOIN quizzes q ON qs.quiz_id=q.id WHERE q.class_id=$class_id");
$conn->query("DELETE qq FROM quiz_questions qq JOIN quizzes q ON qq.quiz_id=q.id WHERE q.class_id=$class_id");
$conn->query("DELETE FROM quizzes WHERE class_id=$class_id");

// Assignments & Submissions
$conn->query("DELETE s FROM assignment_submissions s JOIN assignments a ON s.assignment_id=a.id WHERE a.class_id=$class_id");
$conn->query("DELETE FROM assignments WHERE class_id=$class_id");

// Modules, Folders & Analysis
$conn->query("DELETE FROM class_material_analysis WHERE class_id=$class_id");
$conn->query("DELETE FROM class_modules WHERE class_id=$class_id");
$conn->query("DELETE FROM class_material_folders WHERE class_id=$class_id");
$conn->query("DELETE FROM class_module_links WHERE class_id=$class_id");

// Attendance (F2F & WebRTC Live Sessions)
$conn->query("DELETE FROM class_attendance_records WHERE class_id=$class_id");
$conn->query("DELETE FROM class_attendance_sessions WHERE class_id=$class_id");
$conn->query("DELETE FROM live_attendance WHERE session_id IN (SELECT id FROM live_sessions WHERE class_id=$class_id)");
$conn->query("DELETE FROM live_admission WHERE session_id IN (SELECT id FROM live_sessions WHERE class_id=$class_id)");
$conn->query("DELETE FROM live_peers WHERE session_id IN (SELECT id FROM live_sessions WHERE class_id=$class_id)");
$conn->query("DELETE FROM live_sessions WHERE class_id=$class_id");

// Gradebook, Scores & Weights
$conn->query("DELETE FROM class_record_scores WHERE class_id=$class_id");
$conn->query("DELETE FROM class_record_columns WHERE class_id=$class_id");
$conn->query("DELETE FROM class_record_weights WHERE class_id=$class_id");
$conn->query("DELETE FROM published_grades WHERE class_id=$class_id");

// Analytics & Topic Performance
$conn->query("DELETE FROM class_topic_difficulty WHERE class_id=$class_id");
$conn->query("DELETE FROM student_weak_topics WHERE class_id=$class_id");
$conn->query("DELETE FROM topic_performance WHERE class_id=$class_id");
$conn->query("DELETE FROM teacher_violations WHERE class_id=$class_id");

// Memberships & Logbook
$conn->query("DELETE FROM class_members WHERE class_id=$class_id");
$conn->query("DELETE FROM subject_logbook WHERE class_id=$class_id");

// Finally delete the class record itself from classes table
$delRes = $conn->query("DELETE FROM classes WHERE id=$class_id");

if($delRes){
    echo json_encode(['success'=>true,'msg'=>'Class and all attached database records deleted successfully. You can now reuse this subject name.']);
} else {
    echo json_encode(['success'=>false,'msg'=>'Failed to delete class: ' . $conn->error]);
}
exit;
