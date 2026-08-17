<?php
session_start();
include '../includes/conn.php';
header('Content-Type: application/json');

if(empty($_SESSION['user'])){ echo json_encode(['success'=>false,'msg'=>'Not logged in']); exit; }
$user = $_SESSION['user'];
$uc     = $conn->real_escape_string($user['user_code']);
$role   = strtoupper($user['user_group']);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Teacher: Create Assignment ────────────────────────────────────────────
if($action === 'create' && $role === 'TEACHER'){
    $title       = trim($_POST['title'] ?? '');
    $instructions= trim($_POST['instructions'] ?? '');
    $points      = intval($_POST['points'] ?? 100);
    $due_date    = trim($_POST['due_date'] ?? '');
    $is_active   = isset($_POST['is_active']) ? (intval($_POST['is_active']) ? 1 : 0) : 1;

    $class_ids = [];
    if(!empty($_POST['class_ids']) && is_array($_POST['class_ids'])){
        $class_ids = array_map('intval', $_POST['class_ids']);
    } else if(!empty($_POST['class_ids']) && is_string($_POST['class_ids'])){
        $class_ids = array_map('intval', explode(',', $_POST['class_ids']));
    } else if(!empty($_POST['class_id'])){
        $class_ids = [intval($_POST['class_id'])];
    }
    $class_ids = array_filter($class_ids, function($v){ return $v > 0; });

    if(empty($class_ids) || !$title){
        echo json_encode(['success'=>false,'msg'=>'Target class and assignment title are required']); exit;
    }

    $t  = $conn->real_escape_string($title);
    $ins= $conn->real_escape_string($instructions);
    $due_clean = str_replace('T', ' ', $due_date);
    $due_ts    = strtotime($due_clean);
    $dd = ($due_ts !== false && !empty($due_date)) ? "'".date('Y-m-d H:i:s', $due_ts)."'" : 'NULL';
    $term = $conn->real_escape_string(trim($_POST['term'] ?? 'midterm'));
    if(!in_array($term, ['midterm','final','none'])) $term = 'midterm';

    $created_ids = [];
    foreach($class_ids as $cid){
        // Verify ownership
        $q = $conn->query("SELECT id FROM classes WHERE id=$cid AND teacher_code='$uc'");
        if($q->num_rows === 0) continue;

        $conn->query("INSERT INTO assignments (class_id,teacher_code,title,instructions,points,due_date,term,is_active)
                      VALUES ($cid,'$uc','$t','$ins',$points,$dd,'$term',$is_active)");
        $assignment_id = $conn->insert_id;
        $created_ids[] = $assignment_id;

        // Auto-create class_record_columns entry if term != 'none'
        if($term !== 'none'){
            $asColTitle = $conn->real_escape_string($title);
            $colCheck = $conn->query("SELECT id FROM class_record_columns WHERE class_id=$cid AND assignment_id=$assignment_id LIMIT 1");
            if(!$colCheck || $colCheck->num_rows === 0){
                $conn->query("INSERT INTO class_record_columns (class_id, component, title, max_score, sort_order, term, assignment_id)
                              VALUES ($cid, 'performance', '$asColTitle', $points, 0, '$term', $assignment_id)");
            }
        }
    }

    if(empty($created_ids)){
        echo json_encode(['success'=>false,'msg'=>'No valid target classes authorized']); exit;
    }

    echo json_encode(['success'=>true,'msg'=>'Assignment created successfully','ids'=>$created_ids,'id'=>$created_ids[0]]);
    exit;
}

// ── Teacher: Delete Assignment ────────────────────────────────────────────
if($action === 'delete' && $role === 'TEACHER'){
    $idStr = trim($_POST['id'] ?? $_POST['ids'] ?? '0');
    $idArray = array_map('intval', explode(',', $idStr));
    $validIds = array_filter($idArray, function($v){ return $v > 0; });
    if(empty($validIds)){ echo json_encode(['success'=>false,'msg'=>'Invalid assignment ID']); exit; }
    $idList = implode(',', $validIds);

    $q = $conn->query("SELECT id FROM assignments WHERE id IN ($idList) AND teacher_code='$uc'");
    if($q->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Not found']); exit; }

    $authIds = [];
    while($r = $q->fetch_assoc()) $authIds[] = intval($r['id']);
    $authList = implode(',', $authIds);

    // Delete submission files
    $subs = $conn->query("SELECT file_name FROM assignment_submissions WHERE assignment_id IN ($authList)");
    while($s = $subs->fetch_assoc()){
        if($s['file_name']) @unlink(__DIR__.'/../uploads/submissions/'.$s['file_name']);
    }
    $conn->query("DELETE FROM assignment_submissions WHERE assignment_id IN ($authList)");
    
    // Clean up class record columns and scores
    $colQ = $conn->query("SELECT id FROM class_record_columns WHERE assignment_id IN ($authList)");
    if($colQ && $colQ->num_rows > 0) {
        $colIds = [];
        while($cr = $colQ->fetch_assoc()) $colIds[] = intval($cr['id']);
        if(!empty($colIds)){
            $colList = implode(',', $colIds);
            $conn->query("DELETE FROM class_record_scores WHERE column_id IN ($colList)");
            $conn->query("DELETE FROM class_record_columns WHERE id IN ($colList)");
        }
    }

    $conn->query("DELETE FROM assignments WHERE id IN ($authList)");
    echo json_encode(['success'=>true]);
    exit;
}

// ── Teacher: Toggle Active Status ──────────────────────────────────────────
if($action === 'toggle_active' && $role === 'TEACHER'){
    $idStr = trim($_POST['ids'] ?? $_POST['id'] ?? '0');
    $is_active = isset($_POST['is_active']) ? (intval($_POST['is_active']) ? 1 : 0) : 1;
    $idArray = array_map('intval', explode(',', $idStr));
    $validIds = array_filter($idArray, function($v){ return $v > 0; });

    if(!empty($validIds)){
        $idList = implode(',', $validIds);
        $conn->query("UPDATE assignments SET is_active=$is_active WHERE id IN ($idList) AND teacher_code='$uc'");
        echo json_encode(['success'=>true, 'is_active'=>$is_active]);
    } else {
        echo json_encode(['success'=>false, 'msg'=>'Invalid assignment ID']);
    }
    exit;
}

// ── Teacher: Assign / Copy Assignment to Another Class ───────────────────
if($action === 'assign_to_class' && $role === 'TEACHER'){
    $assignment_id = intval($_POST['assignment_id'] ?? 0);
    $target_class_id = intval($_POST['target_class_id'] ?? 0);

    if(!$assignment_id || !$target_class_id){ echo json_encode(['success'=>false, 'msg'=>'Missing required parameters']); exit; }

    // Check source assignment ownership
    $asQ = $conn->query("SELECT * FROM assignments WHERE id=$assignment_id AND teacher_code='$uc' LIMIT 1");
    if(!$asQ || $asQ->num_rows === 0){ echo json_encode(['success'=>false, 'msg'=>'Source assignment not found']); exit; }
    $as = $asQ->fetch_assoc();

    // Check target class ownership
    $tcQ = $conn->query("SELECT id FROM classes WHERE id=$target_class_id AND teacher_code='$uc' LIMIT 1");
    if(!$tcQ || $tcQ->num_rows === 0){ echo json_encode(['success'=>false, 'msg'=>'Target class not found']); exit; }

    // Check if already assigned to target class
    $titleEsc = $conn->real_escape_string($as['title']);
    $dupCheck = $conn->query("SELECT id FROM assignments WHERE class_id=$target_class_id AND title='$titleEsc' AND teacher_code='$uc' LIMIT 1");
    if($dupCheck && $dupCheck->num_rows > 0){
        echo json_encode(['success'=>false, 'msg'=>'This assignment is already assigned to the target class']);
        exit;
    }

    $ins = $conn->real_escape_string($as['instructions']);
    $pts = intval($as['points']);
    $term = $conn->real_escape_string($as['term']);
    $due = $as['due_date'] ? "'".$conn->real_escape_string($as['due_date'])."'" : 'NULL';
    $act = intval($as['is_active'] ?? 1);

    $conn->query("INSERT INTO assignments (class_id, teacher_code, title, instructions, points, due_date, term, is_active)
                  VALUES ($target_class_id, '$uc', '$titleEsc', '$ins', $pts, $due, '$term', $act)");
    $new_id = $conn->insert_id;

    if($term !== 'none'){
        $conn->query("INSERT INTO class_record_columns (class_id, component, title, max_score, sort_order, term, assignment_id)
                      VALUES ($target_class_id, 'performance', '$titleEsc', $pts, 0, '$term', $new_id)");
    }

    echo json_encode(['success'=>true, 'msg'=>'Assignment assigned to target class successfully', 'new_id'=>$new_id]);
    exit;
}

// ── Teacher: AI Relevance & Quality Analytics ──────────────────────────────
if($action === 'analyze_relevance' && $role === 'TEACHER'){
    $assignment_id = intval($_GET['assignment_id'] ?? $_POST['assignment_id'] ?? 0);
    $q = $conn->query("
        SELECT a.*, c.class_name, c.subject
        FROM assignments a
        LEFT JOIN classes c ON a.class_id = c.id
        WHERE a.id = $assignment_id AND (a.teacher_code = '$uc' OR c.teacher_code = '$uc')
        LIMIT 1
    ");

    if(!$q || $q->num_rows === 0){
        echo json_encode(['success'=>false, 'msg'=>'Assignment not found']); exit;
    }
    $as = $q->fetch_assoc();

    $title = $as['title'];
    $instructions = $as['instructions'] ?? '';
    $points = intval($as['points']);
    $subject = $as['subject'] ?? 'General';
    $wordCount = str_word_count(strip_tags($instructions));

    // Calculate score parameters
    $clarityScore = $wordCount > 15 ? min(98, 70 + ($wordCount > 40 ? 25 : 15)) : 62;
    $alignmentScore = !empty($as['due_date']) ? 94 : 82;
    $rubricScore = ($points >= 10 && $points <= 100) ? 92 : 80;
    $overallScore = round(($clarityScore + $alignmentScore + $rubricScore) / 3, 1);

    $suggestions = [];
    if($wordCount < 20) {
        $suggestions[] = "Provide detailed step-by-step guidelines or formatting criteria to help students understand expected deliverables.";
    } else {
        $suggestions[] = "Instructions are detailed and clearly outline assignment objectives.";
    }

    if(empty($as['due_date'])){
        $suggestions[] = "Set a specific due date & time to help students manage their submission schedule effectively.";
    } else {
        $suggestions[] = "Due date is set (" . date('M d, Y g:i A', strtotime($as['due_date'])) . ").";
    }

    if($points % 5 !== 0){
        $suggestions[] = "Consider standardizing point values to increments of 5 or 10 for easier gradebook calculation.";
    }

    $suggestions[] = "Consider attaching a scoring rubric breakdown (e.g. Content 40%, Organization 30%, Formatting 30%).";

    echo json_encode([
        'success' => true,
        'analysis' => [
            'assignment_id' => $as['id'],
            'title' => $as['title'],
            'subject' => $subject,
            'class_name' => $as['class_name'] ?? 'Class',
            'overall_score' => $overallScore,
            'clarity_score' => $clarityScore,
            'alignment_score' => $alignmentScore,
            'rubric_score' => $rubricScore,
            'bloom_level' => ($wordCount > 30 ? 'Application & Analysis' : 'Understanding & Comprehension'),
            'estimated_time' => ($points > 50 ? '3-5 Hours' : '1-2 Hours'),
            'suggestions' => $suggestions
        ]
    ]);
    exit;
}

// ── Teacher: Get Assignment Submissions ────────────────────────────────────
if(($action === 'get_submissions' || $action === 'get_submissions_json') && $role === 'TEACHER'){
    $assignment_id = intval($_GET['assignment_id'] ?? $_POST['assignment_id'] ?? 0);
    $subsQ = $conn->query("
        SELECT s.*, u.first_name, u.last_name, u.email, u.program_code, u.section
        FROM assignment_submissions s
        JOIN users u ON s.student_code = u.user_code
        WHERE s.assignment_id = $assignment_id
        ORDER BY s.submitted_at DESC
    ");
    $subs = [];
    if($subsQ){
        while($r = $subsQ->fetch_assoc()) $subs[] = $r;
    }
    echo json_encode(['success'=>true, 'submissions'=>$subs]);
    exit;
}

// ── Teacher: Grade Submission ─────────────────────────────────────────────
if($action === 'grade' && $role === 'TEACHER'){
    $sub_id = intval($_POST['sub_id'] ?? 0);
    $grade  = floatval($_POST['grade'] ?? 0);
    $conn->query("UPDATE assignment_submissions SET grade=$grade WHERE id=$sub_id");

    // Auto-sync assignment score into class_record_scores
    // Get assignment details
    $subQ = $conn->query("SELECT s.student_code, s.assignment_id, a.class_id, a.points FROM assignment_submissions s JOIN assignments a ON s.assignment_id = a.id WHERE s.id = $sub_id LIMIT 1");
    if($subQ && $subQ->num_rows > 0){
        $subRow = $subQ->fetch_assoc();
        $student_code = $conn->real_escape_string($subRow['student_code']);
        $assignment_id = intval($subRow['assignment_id']);
        $class_id = intval($subRow['class_id']);
        $points = floatval($subRow['points']);

        $syncQ = $conn->query("SELECT col.id, col.max_score FROM class_record_columns col WHERE col.assignment_id = $assignment_id AND col.class_id = $class_id LIMIT 1");
        if($syncQ && $syncQ->num_rows > 0){
            $syncRow = $syncQ->fetch_assoc();
            $col_id = intval($syncRow['id']);
            $maxScore = floatval($syncRow['max_score']);

            // Scale score to the column's max_score if needed
            $scaledScore = ($points > 0 && $maxScore > 0)
                ? round(($grade / $points) * $maxScore, 2)
                : $grade;

            $conn->query("INSERT INTO class_record_scores (column_id, class_id, student_code, score)
                          VALUES ($col_id, $class_id, '$student_code', $scaledScore)
                          ON DUPLICATE KEY UPDATE score = $scaledScore");
        }
    }

    echo json_encode(['success'=>true]);
    exit;
}

// ── Student: Submit Assignment ────────────────────────────────────────────
if($action === 'submit' && $role === 'STUDENT'){
    $assign_id = intval($_POST['assignment_id'] ?? 0);
    $remarks   = trim($_POST['remarks'] ?? '');

    if(!$assign_id){ echo json_encode(['success'=>false,'msg'=>'Invalid assignment']); exit; }

    // Check already submitted
    $chk = $conn->query("SELECT id FROM assignment_submissions WHERE assignment_id=$assign_id AND student_code='$uc'");
    if($chk->num_rows > 0){ echo json_encode(['success'=>false,'msg'=>'Already submitted']); exit; }

    $file_name = null; $orig_name = null; $file_size = 0;

    if(!empty($_FILES['file']['name'])){
        $allowed = ['pdf','doc','docx','ppt','pptx','xls','xlsx','txt','zip','png','jpg','jpeg'];
        $orig    = $_FILES['file']['name'];
        $ext     = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if(!in_array($ext, $allowed)){ echo json_encode(['success'=>false,'msg'=>'File type not allowed']); exit; }
        if($_FILES['file']['size'] > 20*1024*1024){ echo json_encode(['success'=>false,'msg'=>'Max 20MB']); exit; }
        $file_name = uniqid('sub_').'.'.$ext;
        $uploadDir = __DIR__.'/../uploads/submissions/';
        if(!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        if(!move_uploaded_file($_FILES['file']['tmp_name'], $uploadDir.$file_name)){
            echo json_encode(['success'=>false,'msg'=>'Upload failed']); exit;
        }
        $orig_name = $orig;
        $file_size = intval($_FILES['file']['size']);
    }

    $fn  = $file_name ? "'".$conn->real_escape_string($file_name)."'" : 'NULL';
    $on  = $orig_name ? "'".$conn->real_escape_string($orig_name)."'" : 'NULL';
    $rem = $conn->real_escape_string($remarks);

    $conn->query("INSERT INTO assignment_submissions (assignment_id,student_code,file_name,original_name,file_size,remarks)
                  VALUES ($assign_id,'$uc',$fn,$on,$file_size,'$rem')");
    echo json_encode(['success'=>true,'msg'=>'Assignment submitted successfully']);
    exit;
}

echo json_encode(['success'=>false,'msg'=>'Invalid action']);
