<?php
session_start();
include '../includes/conn.php';
header('Content-Type: application/json');

if(empty($_SESSION['user'])){ echo json_encode(['success'=>false,'msg'=>'Not logged in']); exit; }
$user = $_SESSION['user'];
$tc   = $conn->real_escape_string($user['user_code']);
if(strtoupper($user['user_group']) !== 'TEACHER'){ echo json_encode(['success'=>false,'msg'=>'Unauthorized']); exit; }

$class_id     = intval($_GET['class_id'] ?? 0);
$student_code = $conn->real_escape_string($_GET['student_code'] ?? '');

if(!$class_id || !$student_code){ echo json_encode(['success'=>false,'msg'=>'Invalid request']); exit; }

// Verify teacher owns this class
$cq = $conn->query("SELECT id FROM classes WHERE id=$class_id AND teacher_code='$tc'");
if($cq->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Unauthorized']); exit; }

// Student profile
$sq = $conn->query("SELECT user_code, first_name, middle_name, last_name, email_address, year_level, section, program_code, program_description FROM users WHERE user_code='$student_code'");
if($sq->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Student not found']); exit; }
$student = $sq->fetch_assoc();

// Assignments — all in this class with submission status
$assignments = [];
$aq = $conn->query("
    SELECT a.id, a.title, a.points, a.due_date,
           s.grade, s.submitted_at, s.remarks, s.file_name, s.original_name
    FROM assignments a
    LEFT JOIN assignment_submissions s ON s.assignment_id=a.id AND s.student_code='$student_code'
    WHERE a.class_id=$class_id
    ORDER BY a.created_at ASC
");
$assign_grades = []; $assign_total = 0;
while($r = $aq->fetch_assoc()){
    $assignments[] = $r;
    if($r['grade'] !== null && $r['points'] > 0){
        $assign_grades[] = ($r['grade']/$r['points'])*100;
    }
}
$assign_avg = count($assign_grades) ? array_sum($assign_grades)/count($assign_grades) : null;

// Quizzes — all active in this class with submission
$quizzes = [];
$qq = $conn->query("
    SELECT q.id, q.title, q.time_limit,
           qs.score, qs.total_points, qs.submitted_at, qs.tab_switches, qs.fullscreen_exits
    FROM quizzes q
    LEFT JOIN quiz_submissions qs ON qs.quiz_id=q.id AND qs.student_code='$student_code'
    WHERE q.class_id=$class_id AND q.is_active=1
    ORDER BY q.created_at ASC
");
$quiz_pcts = [];
while($r = $qq->fetch_assoc()){
    $quizzes[] = $r;
    if($r['score'] !== null && $r['total_points'] > 0){
        $quiz_pcts[] = ($r['score']/$r['total_points'])*100;
    }
}
$quiz_avg = count($quiz_pcts) ? array_sum($quiz_pcts)/count($quiz_pcts) : null;

// Attendance
$atq = $conn->query("
    SELECT COUNT(la.id) AS cnt
    FROM live_attendance la
    JOIN live_sessions ls ON la.session_id=ls.id
    WHERE ls.class_id=$class_id AND la.student_code='$student_code'
");
$sessions_attended = intval($atq->fetch_assoc()['cnt']);

echo json_encode([
    'success'           => true,
    'student'           => $student,
    'assignments'       => $assignments,
    'quizzes'           => $quizzes,
    'assign_avg'        => $assign_avg,
    'quiz_avg'          => $quiz_avg,
    'sessions_attended' => $sessions_attended,
]);
?>
