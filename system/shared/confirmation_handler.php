<?php
session_start();
include '../includes/conn.php';
header('Content-Type: application/json');

if(empty($_SESSION['user'])){ echo json_encode(['success'=>false,'msg'=>'Not logged in']); exit; }
$user = $_SESSION['user'];
$uc   = $conn->real_escape_string($user['user_code']);
$role = strtoupper($user['user_group']);

// Auto-create table
$conn->query("CREATE TABLE IF NOT EXISTS `class_confirmations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `student_code` varchar(50) NOT NULL,
  `status` enum('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  `responded_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_student` (`class_id`,`student_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Student: respond to a confirmation ───────────────────────────────────
if($action === 'respond' && $role === 'STUDENT'){
    $class_id = intval($_POST['class_id'] ?? 0);
    $status   = $_POST['status'] ?? '';

    if(!$class_id){ echo json_encode(['success'=>false,'msg'=>'Missing class_id']); exit; }
    if(!in_array($status, ['accepted','declined'])){ echo json_encode(['success'=>false,'msg'=>'Invalid status']); exit; }

    // Verify student is actually enrolled in this class
    $chk = $conn->query("SELECT id FROM class_members WHERE class_id=$class_id AND user_code='$uc'");
    if(!$chk || $chk->num_rows === 0){
        echo json_encode(['success'=>false,'msg'=>'You are not enrolled in this class']);
        exit;
    }

    $st = $conn->real_escape_string($status);
    $ok = $conn->query("
        INSERT INTO class_confirmations (class_id, student_code, status, responded_at)
        VALUES ($class_id, '$uc', '$st', NOW())
        ON DUPLICATE KEY UPDATE status='$st', responded_at=NOW()
    ");

    if($ok){
        echo json_encode(['success'=>true, 'status'=>$status]);
    } else {
        echo json_encode(['success'=>false,'msg'=>'Database error: '.$conn->error]);
    }
    exit;
}

// ── Teacher: get confirmation summary for a specific class ────────────────
if($action === 'summary' && $role === 'TEACHER'){
    $class_id = intval($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
    $tc = $conn->real_escape_string($user['user_code']);

    if(!$class_id){ echo json_encode(['success'=>false,'msg'=>'Missing class_id']); exit; }

    // Verify teacher owns this class
    $chk = $conn->query("SELECT id FROM classes WHERE id=$class_id AND teacher_code='$tc'");
    if(!$chk || $chk->num_rows === 0){
        echo json_encode(['success'=>false,'msg'=>'Unauthorized']);
        exit;
    }

    // Get all enrolled students with their confirmation status
    // COALESCE ensures students with no row in class_confirmations show as 'pending'
    $res = $conn->query("
        SELECT u.user_code, u.first_name, u.last_name, u.section, u.year_level,
               COALESCE(cc.status, 'pending') AS status,
               cc.responded_at
        FROM class_members cm
        JOIN users u ON cm.user_code = u.user_code
        LEFT JOIN class_confirmations cc
               ON cc.class_id = $class_id AND cc.student_code = u.user_code
        WHERE cm.class_id = $class_id
          AND u.user_group = 'STUDENT'
        ORDER BY u.last_name, u.first_name
    ");

    $list     = [];
    $accepted = 0;
    $declined = 0;
    $pending  = 0;

    while($r = $res->fetch_assoc()){
        $list[] = $r;
        if($r['status'] === 'accepted')      $accepted++;
        elseif($r['status'] === 'declined')  $declined++;
        else                                  $pending++;
    }

    echo json_encode([
        'success'  => true,
        'students' => $list,
        'accepted' => $accepted,
        'declined' => $declined,
        'pending'  => $pending,
        'total'    => count($list),
    ]);
    exit;
}

// ── Teacher: inbox overview (all classes) ─────────────────────────────────
if($action === 'inbox' && $role === 'TEACHER'){
    $tc = $conn->real_escape_string($user['user_code']);
    $res = $conn->query("
        SELECT cc.*, c.class_name, c.subject, c.section, c.year_level,
               u.first_name, u.last_name, u.user_code AS stu_code
        FROM class_confirmations cc
        JOIN classes c  ON cc.class_id    = c.id
        JOIN users u    ON cc.student_code = u.user_code
        WHERE c.teacher_code = '$tc'
        ORDER BY cc.responded_at DESC, cc.created_at DESC
        LIMIT 100
    ");
    $list = [];
    while($r = $res->fetch_assoc()) $list[] = $r;
    echo json_encode(['success'=>true,'items'=>$list]);
    exit;
}

// ── Student: get all confirmations ────────────────────────────────────────
if($action === 'pending' && $role === 'STUDENT'){
    $res = $conn->query("
        SELECT c.id AS class_id, c.class_name, c.subject, c.section, c.year_level,
               c.schedule_json, c.schedule_room,
               u.first_name AS teacher_first, u.last_name AS teacher_last,
               COALESCE(cc.status, 'pending') AS status,
               cc.responded_at
        FROM class_members cm
        JOIN classes c ON cm.class_id = c.id
        LEFT JOIN users u ON c.teacher_code = u.user_code
        LEFT JOIN class_confirmations cc
               ON cc.class_id = c.id AND cc.student_code = '$uc'
        WHERE cm.user_code = '$uc'
          AND c.teacher_code != '$uc'
          AND (c.is_archived = 0 OR c.is_archived IS NULL)
        ORDER BY cm.joined_at DESC
    ");
    $list    = [];
    $pending = 0;
    while($r = $res->fetch_assoc()){
        $list[] = $r;
        if($r['status'] === 'pending') $pending++;
    }
    echo json_encode(['success'=>true,'items'=>$list,'pending_count'=>$pending]);
    exit;
}

// ── Student: delete a single notification ─────────────────────────────────
if(($action === 'delete' || $action === 'delete_notification') && $role === 'STUDENT'){
    $class_id = intval($_POST['class_id'] ?? 0);
    if(!$class_id){ echo json_encode(['success'=>false,'msg'=>'Missing class_id']); exit; }

    $conn->query("DELETE FROM class_confirmations WHERE class_id=$class_id AND student_code='$uc'");
    echo json_encode(['success'=>true, 'class_id'=>$class_id]);
    exit;
}

// ── Student: clear all notifications ────────────────────────────────────
if(($action === 'clear_all' || $action === 'clear_all_notifications') && $role === 'STUDENT'){
    $conn->query("DELETE FROM class_confirmations WHERE student_code='$uc'");
    echo json_encode(['success'=>true]);
    exit;
}

echo json_encode(['success'=>false,'msg'=>'Invalid action or insufficient permissions']);
?>
