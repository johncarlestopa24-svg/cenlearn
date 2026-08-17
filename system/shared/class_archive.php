<?php
session_start();
include '../includes/conn.php';
header('Content-Type: application/json');

if(empty($_SESSION['user'])){ echo json_encode(['success'=>false,'msg'=>'Not logged in']); exit; }
$user = $_SESSION['user'];
$uc   = $conn->real_escape_string($user['user_code']);
$role = strtoupper($user['user_group']);

if($role !== 'TEACHER'){ echo json_encode(['success'=>false,'msg'=>'Unauthorized']); exit; }

// Auto-migrate
safeAddColumns($conn, 'classes', [
    'school_year' => 'varchar(20) DEFAULT NULL',
    'is_archived' => 'tinyint(1) NOT NULL DEFAULT 0',
    'archived_at' => 'datetime DEFAULT NULL'
]);

$action   = $_POST['action'] ?? '';
$class_id = intval($_POST['class_id'] ?? 0);

// Verify ownership
if($class_id){
    $chk = $conn->query("SELECT id FROM classes WHERE id=$class_id AND teacher_code='$uc'");
    if(!$chk || $chk->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Unauthorized']); exit; }
}

// ── Archive a class ───────────────────────────────────────────────────────
if($action === 'archive'){
    $school_year = $conn->real_escape_string(trim($_POST['school_year'] ?? ''));
    if(!$school_year){
        // Auto-detect: e.g. "2024-2025"
        $y = intval(date('Y'));
        $m = intval(date('n'));
        $school_year = $m >= 6 ? "$y-".($y+1) : ($y-1)."-$y";
    }
    $conn->query("UPDATE classes SET is_archived=1, archived_at=NOW(), school_year='$school_year'
                  WHERE id=$class_id AND teacher_code='$uc'");
    echo json_encode(['success'=>true]);
    exit;
}

// ── Unarchive a class ─────────────────────────────────────────────────────
if($action === 'unarchive'){
    $conn->query("UPDATE classes SET is_archived=0, archived_at=NULL
                  WHERE id=$class_id AND teacher_code='$uc'");
    echo json_encode(['success'=>true]);
    exit;
}

echo json_encode(['success'=>false,'msg'=>'Invalid action']);
?>
