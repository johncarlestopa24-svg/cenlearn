<?php
session_start();
include '../includes/conn.php';

// Session check

header('Content-Type: application/json');

if(empty($_SESSION['user'])){
    echo json_encode(['success'=>false,'msg'=>'Not logged in']); exit;
}
$user = $_SESSION['user'];
$tc   = $conn->real_escape_string($user['user_code']);
if(strtoupper($user['user_group']) !== 'TEACHER'){
    echo json_encode(['success'=>false,'msg'=>'Unauthorized access']); exit;
}

$action   = $_POST['action'] ?? $_GET['action'] ?? '';
$class_id = intval($_POST['class_id'] ?? $_GET['class_id'] ?? 0);

if($class_id){
    $chk = $conn->query("SELECT id FROM classes WHERE id=$class_id AND teacher_code='$tc'");
    if($chk->num_rows === 0){
        echo json_encode(['success'=>false,'msg'=>'Unauthorized class access']); exit;
    }
}

// ── Save or Take Attendance Session ──────────────────────────────────────────
if($action === 'save_attendance'){
    $session_id  = intval($_POST['session_id'] ?? 0);
    $title       = $conn->real_escape_string(trim($_POST['title'] ?? ''));
    $date        = $conn->real_escape_string($_POST['date'] ?? date('Y-m-d'));
    $term        = $conn->real_escape_string($_POST['term'] ?? 'midterm');
    $remarks     = $conn->real_escape_string(trim($_POST['remarks'] ?? ''));
    $recordsRaw  = $_POST['records'] ?? '[]';
    $records     = is_array($recordsRaw) ? $recordsRaw : json_decode($recordsRaw, true);

    if(!in_array($term, ['midterm','final'])) $term = 'midterm';
    if(!$title){
        $title = 'F2F Attendance - ' . date('M d, Y', strtotime($date));
    }

    if($session_id > 0){
        $conn->query("UPDATE class_attendance_sessions SET title='$title', attendance_date='$date', term='$term', remarks='$remarks' WHERE id=$session_id AND class_id=$class_id");
    } else {
        $conn->query("INSERT INTO class_attendance_sessions (class_id, teacher_code, title, attendance_date, term, remarks) VALUES ($class_id, '$tc', '$title', '$date', '$term', '$remarks')");
        $session_id = $conn->insert_id;
    }

    if(!$session_id){
        echo json_encode(['success'=>false, 'msg'=>'Failed to save attendance session']); exit;
    }

    // Auto-sync / auto-insert into class_record_columns
    $col_id = 0;
    try {
        $created_at = $date . ' ' . date('H:i:s');
        $displayTitle = date('M d', strtotime($date));
        $colCheck = $conn->query("SELECT id FROM class_record_columns WHERE class_id=$class_id AND (attendance_session_id=$session_id OR (is_f2f=1 AND session_id=$session_id) OR (is_f2f=1 AND created_at LIKE '$date%')) LIMIT 1");
        if(!$colCheck || $colCheck->num_rows === 0){
            $conn->query("INSERT INTO class_record_columns (class_id, component, title, max_score, sort_order, term, session_id, attendance_session_id, is_f2f, created_at)
                          VALUES ($class_id, 'attendance', '$displayTitle', 1.00, 0, '$term', $session_id, $session_id, 1, '$created_at')");
            $col_id = (int)$conn->insert_id;
        } else {
            $col_id = intval($colCheck->fetch_assoc()['id']);
            $conn->query("UPDATE class_record_columns SET component='attendance', title='$displayTitle', term='$term', session_id=$session_id, attendance_session_id=$session_id, is_f2f=1, created_at='$created_at' WHERE id=$col_id");
        }
    } catch (\Throwable $e) {
        error_log("Attendance class_record_columns sync notice: " . $e->getMessage());
    }

    // Save individual student attendance records & sync to class_record_scores
    if(is_array($records)){
        foreach($records as $rec){
            $stuCode = $conn->real_escape_string($rec['student_code'] ?? '');
            $status  = strtolower($rec['status'] ?? 'present');
            $recRem  = $conn->real_escape_string($rec['remarks'] ?? '');

            if(!in_array($status, ['present','late','absent','excused'])) $status = 'present';
            if(!$stuCode) continue;

            $conn->query("INSERT INTO class_attendance_records (session_id, class_id, student_code, status, remarks)
                          VALUES ($session_id, $class_id, '$stuCode', '$status', '$recRem')
                          ON DUPLICATE KEY UPDATE status='$status', remarks='$recRem'");

            // Convert status to numeric score for Class Record
            // Present: 1.00, Late: 0.50, Excused: 1.00, Absent: 0.00
            $score = 1.00;
            if($status === 'late')    $score = 0.50;
            if($status === 'absent')  $score = 0.00;
            if($status === 'excused') $score = 1.00;

            if($col_id > 0){
                try {
                    $conn->query("INSERT INTO class_record_scores (column_id, class_id, student_code, score)
                                  VALUES ($col_id, $class_id, '$stuCode', $score)
                                  ON DUPLICATE KEY UPDATE score = $score");
                } catch (\Throwable $e) {
                    error_log("Attendance score sync notice: " . $e->getMessage());
                }
            }
        }
    }

    echo json_encode(['success'=>true, 'session_id'=>$session_id, 'col_id'=>$col_id]);
    exit;
}

// ── Update Single Student Status ─────────────────────────────────────────────
if($action === 'update_student_status'){
    $session_id = intval($_POST['session_id'] ?? 0);
    $stuCode    = $conn->real_escape_string($_POST['student_code'] ?? '');
    $status     = strtolower($_POST['status'] ?? 'present');

    if(!in_array($status, ['present','late','absent','excused'])) $status = 'present';
    if(!$session_id || !$stuCode){
        echo json_encode(['success'=>false, 'msg'=>'Invalid parameters']); exit;
    }

    $conn->query("INSERT INTO class_attendance_records (session_id, class_id, student_code, status)
                  VALUES ($session_id, $class_id, '$stuCode', '$status')
                  ON DUPLICATE KEY UPDATE status='$status'");

    // Sync score to class_record_scores
    $colCheck = $conn->query("SELECT id FROM class_record_columns WHERE class_id=$class_id AND session_id=$session_id LIMIT 1");
    if($colCheck && $colCheck->num_rows > 0){
        $col_id = intval($colCheck->fetch_assoc()['id']);
        $score = 1.00;
        if($status === 'late')   $score = 0.50;
        if($status === 'absent') $score = 0.00;
        if($status === 'excused') $score = 1.00;

        $conn->query("INSERT INTO class_record_scores (column_id, class_id, student_code, score)
                      VALUES ($col_id, $class_id, '$stuCode', $score)
                      ON DUPLICATE KEY UPDATE score = $score");
    }

    echo json_encode(['success'=>true]);
    exit;
}

// ── Delete Attendance Session ────────────────────────────────────────────────
if($action === 'delete_session'){
    $session_id = intval($_POST['session_id'] ?? 0);
    if(!$session_id){
        echo json_encode(['success'=>false, 'msg'=>'Session ID required']); exit;
    }

    $conn->query("DELETE FROM class_attendance_records WHERE session_id=$session_id");
    $conn->query("DELETE FROM class_attendance_sessions WHERE id=$session_id AND class_id=$class_id");

    // Also remove from class record columns & scores
    $colQ = $conn->query("SELECT id FROM class_record_columns WHERE class_id=$class_id AND session_id=$session_id");
    while($c = $colQ->fetch_assoc()){
        $cid = intval($c['id']);
        $conn->query("DELETE FROM class_record_scores WHERE column_id=$cid");
        $conn->query("DELETE FROM class_record_columns WHERE id=$cid");
    }

    echo json_encode(['success'=>true]);
    exit;
}

echo json_encode(['success'=>false, 'msg'=>'Invalid action']);
?>
