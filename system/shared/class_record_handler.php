<?php
session_start();
include '../includes/conn.php';
require_once __DIR__ . '/grading_engine.php';

// Run migrations
safeAddColumns($conn, 'class_record_columns', [
    'term' => "varchar(20) NOT NULL DEFAULT 'midterm'",
    'is_f2f' => 'tinyint(1) NOT NULL DEFAULT 0'
]);
safeAddColumns($conn, 'class_record_weights', [
    'attendance_pct' => 'int(11) NOT NULL DEFAULT 10',
    'deportment_pct' => 'int(11) NOT NULL DEFAULT 10',
    'grading_method' => "varchar(20) NOT NULL DEFAULT 'sum_of_points'",
    'base_grade' => 'int(11) NOT NULL DEFAULT 0',
    'midterm_weight' => 'int(11) NOT NULL DEFAULT 40',
    'final_weight' => 'int(11) NOT NULL DEFAULT 60'
]);
$conn->query("CREATE TABLE IF NOT EXISTS `published_grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `term` varchar(20) NOT NULL,
  `student_code` varchar(50) NOT NULL,
  `grade` decimal(6,2) DEFAULT NULL,
  `transmuted` varchar(10) DEFAULT NULL,
  `remarks` varchar(20) DEFAULT NULL,
  `published_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_term_student` (`class_id`,`term`,`student_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

header('Content-Type: application/json');

if(empty($_SESSION['user'])){ echo json_encode(['success'=>false,'msg'=>'Not logged in']); exit; }
$user = $_SESSION['user'];
$tc   = $conn->real_escape_string($user['user_code']);
if(strtoupper($user['user_group']) !== 'TEACHER'){ echo json_encode(['success'=>false,'msg'=>'Unauthorized']); exit; }

$action   = $_POST['action'] ?? $_GET['action'] ?? '';
$class_id = intval($_POST['class_id'] ?? $_GET['class_id'] ?? 0);

// Verify ownership
if($class_id){
    $chk = $conn->query("SELECT id FROM classes WHERE id=$class_id AND teacher_code='$tc'");
    if($chk->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Unauthorized']); exit; }
}

// ── Add column ────────────────────────────────────────────────────────────
if($action === 'add_column'){
    $comp      = $conn->real_escape_string($_POST['component'] ?? 'written');
    $title     = $conn->real_escape_string(trim($_POST['title'] ?? ''));
    $max_score = floatval($_POST['max_score'] ?? 100);
    if($max_score <= 0) $max_score = 100; // Bug 8 fix: prevent division-by-zero in grade calculation
    $term      = $conn->real_escape_string($_POST['term'] ?? 'midterm');
    if(!in_array($term, ['midterm', 'final'])) $term = 'midterm';
    if(!$title){ echo json_encode(['success'=>false,'msg'=>'Title required']); exit; }
    $conn->query("INSERT INTO class_record_columns (class_id,component,title,max_score,term) VALUES ($class_id,'$comp','$title',$max_score,'$term')");
    echo json_encode(['success'=>true,'id'=>$conn->insert_id]);
    exit;
}

// ── Add F2F column ────────────────────────────────────────────────────────
if($action === 'add_f2f_column'){
    $title     = $conn->real_escape_string(trim($_POST['title'] ?? ''));
    $term      = $conn->real_escape_string($_POST['term'] ?? 'midterm');
    $date      = $conn->real_escape_string($_POST['date'] ?? date('Y-m-d'));
    if(!in_array($term, ['midterm', 'final'])) $term = 'midterm';
    if(!$title){ echo json_encode(['success'=>false,'msg'=>'Title required']); exit; }
    
    $created_at = $date . ' ' . date('H:i:s');
    $displayTitle = date('M d', strtotime($date));
    
    // Also create in class_attendance_sessions to maintain exact date connection
    $conn->query("INSERT INTO class_attendance_sessions (class_id, teacher_code, title, attendance_date, term)
                  VALUES ($class_id, '$tc', '$title', '$date', '$term')");
    $att_sess_id = $conn->insert_id;
    
    $conn->query("INSERT INTO class_record_columns (class_id, component, title, max_score, term, session_id, attendance_session_id, is_f2f, created_at)
                  VALUES ($class_id, 'attendance', '$displayTitle', 1.00, '$term', $att_sess_id, $att_sess_id, 1, '$created_at')");
    echo json_encode(['success'=>true,'id'=>$conn->insert_id]);
    exit;
}

// ── Update Column Max Score ───────────────────────────────────────────────
if($action === 'update_max_score'){
    $col_id = intval($_POST['col_id'] ?? 0);
    $max_score = floatval($_POST['max_score'] ?? 100);
    if($max_score <= 0) $max_score = 100;
    $conn->query("UPDATE class_record_columns SET max_score=$max_score WHERE id=$col_id AND class_id=$class_id");
    echo json_encode(['success'=>true, 'max_score'=>$max_score]);
    exit;
}

// ── Delete column ─────────────────────────────────────────────────────────
if($action === 'delete_column'){
    $col_id = intval($_POST['col_id'] ?? 0);
    $colRow = $conn->query("SELECT attendance_session_id, session_id, is_f2f FROM class_record_columns WHERE id=$col_id AND class_id=$class_id LIMIT 1")->fetch_assoc();
    $asid = intval($colRow['attendance_session_id'] ?? ($colRow['is_f2f'] ? $colRow['session_id'] : 0));
    if($asid > 0){
        $conn->query("DELETE FROM class_attendance_records WHERE session_id=$asid");
        $conn->query("DELETE FROM class_attendance_sessions WHERE id=$asid AND class_id=$class_id");
    }
    $conn->query("DELETE FROM class_record_scores WHERE column_id=$col_id");
    $conn->query("DELETE FROM class_record_columns WHERE id=$col_id AND class_id=$class_id");
    echo json_encode(['success'=>true]);
    exit;
}

// ── Save score ────────────────────────────────────────────────────────────
if($action === 'save_score'){
    $col_id  = intval($_POST['col_id'] ?? 0);
    $stu     = $conn->real_escape_string($_POST['student_code'] ?? '');
    $score   = ($_POST['score'] ?? '') === '' ? 'NULL' : floatval($_POST['score'] ?? 0);
    $statusParam = strtolower(trim($_POST['status'] ?? ''));

    // Bug 2 fix: class_id was missing — caused silent INSERT failure since column is NOT NULL
    $conn->query("INSERT INTO class_record_scores (column_id,class_id,student_code,score) VALUES ($col_id,$class_id,'$stu',$score)
                  ON DUPLICATE KEY UPDATE score=$score");

    // If this column is linked to attendance session, sync status to class_attendance_records
    $colRow = $conn->query("SELECT attendance_session_id, session_id, is_f2f FROM class_record_columns WHERE id=$col_id LIMIT 1")->fetch_assoc();
    $asid = intval($colRow['attendance_session_id'] ?? ($colRow['is_f2f'] ? $colRow['session_id'] : 0));
    if($asid > 0 && $score !== 'NULL'){
        if(in_array($statusParam, ['present', 'late', 'absent', 'excused'])) {
            $status = $statusParam;
        } else {
            $status = ($score >= 1.50) ? 'present' : (($score >= 0.50) ? 'late' : 'absent');
        }
        $conn->query("INSERT INTO class_attendance_records (session_id, class_id, student_code, status)
                      VALUES ($asid, $class_id, '$stu', '$status')
                      ON DUPLICATE KEY UPDATE status='$status'");
    }

    // Return updated real-time calculations for this student
    $term = trim($_POST['term'] ?? 'midterm');
    if(!in_array($term, ['midterm', 'final'])) $term = 'midterm';

    // Weights
    $wq = $conn->query("SELECT * FROM class_record_weights WHERE class_id=$class_id");
    $weights = $wq->num_rows > 0 ? array_merge(GradingEngine::DEFAULT_WEIGHTS, $wq->fetch_assoc()) : GradingEngine::DEFAULT_WEIGHTS;

    // Load columns for both terms
    $allColsQ = $conn->query("SELECT * FROM class_record_columns WHERE class_id=$class_id ORDER BY component, sort_order, id");
    $midColsByComp = ['written'=>[],'performance'=>[],'exam'=>[],'deportment'=>[],'attendance'=>[]];
    $finColsByComp = ['written'=>[],'performance'=>[],'exam'=>[],'deportment'=>[],'attendance'=>[]];
    while($col = $allColsQ->fetch_assoc()) {
        $cTerm = $col['term'] === 'final' ? 'final' : 'midterm';
        $comp = $col['component'];
        if($cTerm === 'final') {
            if($comp === 'deportment') $finColsByComp['deportment'][] = $col;
            elseif(!empty($col['session_id']) || $comp === 'attendance') $finColsByComp['attendance'][] = $col;
            else {
                if(!isset($finColsByComp[$comp])) $finColsByComp[$comp] = [];
                $finColsByComp[$comp][] = $col;
            }
        } else {
            if($comp === 'deportment') $midColsByComp['deportment'][] = $col;
            elseif(!empty($col['session_id']) || $comp === 'attendance') $midColsByComp['attendance'][] = $col;
            else {
                if(!isset($midColsByComp[$comp])) $midColsByComp[$comp] = [];
                $midColsByComp[$comp][] = $col;
            }
        }
    }

    // Fetch scores for this student
    $scoresQ = $conn->query("SELECT column_id, score FROM class_record_scores WHERE class_id=$class_id AND student_code='$stu'");
    $stuScores = [];
    while($sr = $scoresQ->fetch_assoc()) $stuScores[$sr['column_id']][$stu] = $sr['score'];

    $activeColsByComp = ($term === 'midterm') ? $midColsByComp : $finColsByComp;
    $gradeResult = GradingEngine::computeStudentGrade($stu, $activeColsByComp, $stuScores, $weights);
    $midGrade = GradingEngine::computeStudentGrade($stu, $midColsByComp, $stuScores, $weights);
    $finGrade = GradingEngine::computeStudentGrade($stu, $finColsByComp, $stuScores, $weights);

    $midVal = $midGrade['final'];
    $finVal = $finGrade['final'];
    $midPct = floatval($weights['midterm_weight'] ?? 40) / 100;
    $finPct = floatval($weights['final_weight'] ?? 60) / 100;
    $overall = null;
    if ($midVal !== null && $finVal !== null) $overall = round(($midVal * $midPct) + ($finVal * $finPct), 2);
    elseif ($midVal !== null) $overall = $midVal;
    elseif ($finVal !== null) $overall = $finVal;

    $transmutedScale = '—';
    if($overall !== null) {
        $rVal = round(floatval($overall));
        if($rVal >= 98) $transmutedScale = '1.00'; elseif($rVal >= 95) $transmutedScale = '1.25'; elseif($rVal >= 92) $transmutedScale = '1.50';
        elseif($rVal >= 89) $transmutedScale = '1.75'; elseif($rVal >= 86) $transmutedScale = '2.00'; elseif($rVal >= 83) $transmutedScale = '2.25';
        elseif($rVal >= 80) $transmutedScale = '2.50'; elseif($rVal >= 77) $transmutedScale = '2.75'; elseif($rVal >= 75) $transmutedScale = '3.00';
        else $transmutedScale = '5.00';
    }

    $remarks = ($overall !== null) ? ($overall >= 75 ? 'Passed' : 'Failed') : '—';
    $remarksColor = ($overall !== null && $overall >= 75) ? '#166534' : '#991b1b';
    $remarksBg = ($overall !== null && $overall >= 75) ? '#dcfce7' : '#fee2e2';

    // Calculate raw component percentage averages for display
    $compAverages = [];
    foreach(['written', 'performance', 'exam', 'deportment', 'attendance'] as $ck) {
        $raw = $gradeResult['raw'][$ck] ?? null;
        $tot = $gradeResult['total_items'][$ck] ?? 0;
        $compAverages[$ck] = ($raw !== null && $tot > 0) ? round(($raw / $tot) * 100, 1) : null;
    }

    echo json_encode([
        'success'        => true,
        'student_code'   => $stu,
        'term_grade'     => $gradeResult['final'],
        'overall_grade'  => $overall,
        'transmuted'     => $transmutedScale,
        'remarks'        => $remarks,
        'remarks_color'  => $remarksColor,
        'remarks_bg'     => $remarksBg,
        'comp_averages'  => $compAverages,
        'components'     => $gradeResult['components']
    ]);
    exit;
}

// ── Save weights ──────────────────────────────────────────────────────────
if($action === 'save_weights'){
    $a  = intval($_POST['attendance_pct']  ?? 10);
    $w  = intval($_POST['written_pct']     ?? 20);
    $p  = intval($_POST['performance_pct'] ?? 20);
    $e  = intval($_POST['exam_pct']        ?? 40);
    $d  = intval($_POST['deportment_pct']  ?? 10);
    $method = $conn->real_escape_string($_POST['grading_method'] ?? 'sum_of_points');
    $base   = intval($_POST['base_grade'] ?? 50);
    $mid    = intval($_POST['midterm_weight'] ?? 40);
    $fin    = intval($_POST['final_weight'] ?? 60);

    $extraRaw = $_POST['extra_weights'] ?? '[]';
    $extras = json_decode($extraRaw, true);
    if(!is_array($extras)) $extras = [];
    $extraSum = array_sum(array_column($extras, 'pct'));
    if($a+$w+$p+$e+$d+$extraSum !== 100){ echo json_encode(['success'=>false,'msg'=>'Weights must total exactly 100%']); exit; }
    if($mid + $fin !== 100){ echo json_encode(['success'=>false,'msg'=>'Term weights must total exactly 100%']); exit; }

    $extraJson = $conn->real_escape_string(json_encode($extras));
    $conn->query("INSERT INTO class_record_weights (class_id,attendance_pct,written_pct,performance_pct,exam_pct,deportment_pct,extra_weights,grading_method,base_grade,midterm_weight,final_weight)
                  VALUES ($class_id,$a,$w,$p,$e,$d,'$extraJson','$method',$base,$mid,$fin)
                  ON DUPLICATE KEY UPDATE attendance_pct=$a,written_pct=$w,performance_pct=$p,exam_pct=$e,deportment_pct=$d,extra_weights='$extraJson',grading_method='$method',base_grade=$base,midterm_weight=$mid,final_weight=$fin");
    echo json_encode(['success'=>true]);
    exit;
}

// ── Publish Grades ────────────────────────────────────────────────────────
if($action === 'publish_grades'){
    $term = $conn->real_escape_string($_POST['term'] ?? 'midterm');
    if(!in_array($term, ['midterm', 'final'])) $term = 'midterm';

    // Fetch weights
    $wq = $conn->query("SELECT * FROM class_record_weights WHERE class_id=$class_id");
    $weights = $wq->num_rows > 0 ? array_merge(GradingEngine::DEFAULT_WEIGHTS, $wq->fetch_assoc()) : GradingEngine::DEFAULT_WEIGHTS;
    if(!isset($weights['midterm_weight'])) $weights['midterm_weight'] = 40;
    if(!isset($weights['final_weight'])) $weights['final_weight'] = 60;
    if(!isset($weights['extra_weights'])) $weights['extra_weights'] = '[]';

    // Fetch all columns
    $colsQ = $conn->query("SELECT * FROM class_record_columns WHERE class_id=$class_id ORDER BY component,sort_order,id");
    $columns = [];
    while($r = $colsQ->fetch_assoc()) $columns[] = $r;
    
    $midtermCols = array_filter($columns, fn($c) => $c['term'] === 'midterm');
    $finalCols   = array_filter($columns, fn($c) => $c['term'] === 'final');

    $midtermColsByComp = ['written'=>[],'performance'=>[],'exam'=>[],'deportment'=>[],'attendance'=>[]];
    foreach($midtermCols as $col) {
        if($col['component'] === 'deportment') {
            $midtermColsByComp['deportment'][] = $col;
        } elseif(!empty($col['session_id']) || $col['component'] === 'attendance') {
            $midtermColsByComp['attendance'][] = $col;
        } else {
            $compKey = $col['component'];
            if(!isset($midtermColsByComp[$compKey])) $midtermColsByComp[$compKey] = [];
            $midtermColsByComp[$compKey][] = $col;
        }
    }

    $finalColsByComp = ['written'=>[],'performance'=>[],'exam'=>[],'deportment'=>[],'attendance'=>[]];
    foreach($finalCols as $col) {
        if($col['component'] === 'deportment') {
            $finalColsByComp['deportment'][] = $col;
        } elseif(!empty($col['session_id']) || $col['component'] === 'attendance') {
            $finalColsByComp['attendance'][] = $col;
        } else {
            $compKey = $col['component'];
            if(!isset($finalColsByComp[$compKey])) $finalColsByComp[$compKey] = [];
            $finalColsByComp[$compKey][] = $col;
        }
    }

    // Fetch scores
    $scoresQ = $conn->query("SELECT s.* FROM class_record_scores s JOIN class_record_columns col ON s.column_id=col.id WHERE col.class_id=$class_id");
    $scores = [];
    while($r = $scoresQ->fetch_assoc()) $scores[$r['column_id']][$r['student_code']] = $r['score'];

    // Fetch students
    $students = $conn->query("SELECT u.user_code, u.first_name, u.last_name FROM class_members cm JOIN users u ON cm.user_code=u.user_code WHERE cm.class_id=$class_id AND u.user_group='STUDENT'");

    function _transmute($grade) {
        if($grade === null) return '—';
        $r = round(floatval($grade));
        if($r >= 98) return '1.00'; if($r >= 95) return '1.25'; if($r >= 92) return '1.50';
        if($r >= 89) return '1.75'; if($r >= 86) return '2.00'; if($r >= 83) return '2.25';
        if($r >= 80) return '2.50'; if($r >= 77) return '2.75'; if($r >= 75) return '3.00';
        return '5.00';
    }

    function _computeGrade($studentCode, $colsByComp, $scores, $weights) {
        $res = GradingEngine::computeStudentGrade($studentCode, $colsByComp, $scores, $weights);
        return $res['final'];
    }

    $midPct = floatval($weights['midterm_weight'] ?? 40) / 100;
    $finPct = floatval($weights['final_weight'] ?? 60) / 100;

    while($s = $students->fetch_assoc()){
        $sc = $s['user_code'];
        $midVal = _computeGrade($sc, $midtermColsByComp, $scores, $weights);
        $finVal = _computeGrade($sc, $finalColsByComp, $scores, $weights);

        if($term === 'midterm') {
            $gradeVal = $midVal;
        } else {
            if ($midVal !== null && $finVal !== null) {
                $gradeVal = round(($midVal * $midPct) + ($finVal * $finPct), 2);
            } elseif ($midVal !== null) {
                $gradeVal = $midVal;
            } elseif ($finVal !== null) {
                $gradeVal = $finVal;
            } else {
                $gradeVal = null;
            }
        }

        if($gradeVal !== null){
            $trans = _transmute($gradeVal);
            $rem   = round(floatval($gradeVal)) >= 75 ? 'Passed' : 'Failed';
            $conn->query("INSERT INTO published_grades (class_id,term,student_code,grade,transmuted,remarks,published_at)
                          VALUES ($class_id,'$term','$sc',$gradeVal,'$trans','$rem',NOW())
                          ON DUPLICATE KEY UPDATE grade=$gradeVal,transmuted='$trans',remarks='$rem',published_at=NOW()");
        }
    }

    echo json_encode(['success'=>true]);
    exit;
}

echo json_encode(['success'=>false,'msg'=>'Invalid action']);
?>
