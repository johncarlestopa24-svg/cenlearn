<?php
session_start();
include '../includes/conn.php';

// Run migrations
safeAddColumns($conn, 'class_record_columns', [
    'term' => "varchar(20) NOT NULL DEFAULT 'midterm'",
    'is_f2f' => 'tinyint(1) NOT NULL DEFAULT 0'
]);
safeAddColumns($conn, 'class_record_weights', [
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
    
    $conn->query("INSERT INTO class_record_columns (class_id,component,title,max_score,term,is_f2f,created_at) VALUES ($class_id,'written','$title',1.00,'$term',1,'$created_at')");
    echo json_encode(['success'=>true,'id'=>$conn->insert_id]);
    exit;
}

// ── Delete column ─────────────────────────────────────────────────────────
if($action === 'delete_column'){
    $col_id = intval($_POST['col_id'] ?? 0);
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
    // Bug 2 fix: class_id was missing — caused silent INSERT failure since column is NOT NULL
    $conn->query("INSERT INTO class_record_scores (column_id,class_id,student_code,score) VALUES ($col_id,$class_id,'$stu',$score)
                  ON DUPLICATE KEY UPDATE score=$score");
    echo json_encode(['success'=>true]);
    exit;
}

// ── Save weights ──────────────────────────────────────────────────────────
if($action === 'save_weights'){
    $w  = intval($_POST['written_pct']     ?? 20);
    $p  = intval($_POST['performance_pct'] ?? 40);
    $e  = intval($_POST['exam_pct']        ?? 30);
    $a  = intval($_POST['attendance_pct']  ?? 10);
    $method = $conn->real_escape_string($_POST['grading_method'] ?? 'sum_of_points');
    $base   = intval($_POST['base_grade'] ?? 0);
    $mid    = intval($_POST['midterm_weight'] ?? 40);
    $fin    = intval($_POST['final_weight'] ?? 60);

    $extraRaw = $_POST['extra_weights'] ?? '[]';
    $extras = json_decode($extraRaw, true);
    if(!is_array($extras)) $extras = [];
    $extraSum = array_sum(array_column($extras, 'pct'));
    if($w+$p+$e+$a+$extraSum !== 100){ echo json_encode(['success'=>false,'msg'=>'Weights must total 100%']); exit; }
    if($mid + $fin !== 100){ echo json_encode(['success'=>false,'msg'=>'Term weights must total 100%']); exit; }

    $extraJson = $conn->real_escape_string(json_encode($extras));
    $conn->query("INSERT INTO class_record_weights (class_id,written_pct,performance_pct,exam_pct,attendance_pct,extra_weights,grading_method,base_grade,midterm_weight,final_weight)
                  VALUES ($class_id,$w,$p,$e,$a,'$extraJson','$method',$base,$mid,$fin)
                  ON DUPLICATE KEY UPDATE written_pct=$w,performance_pct=$p,exam_pct=$e,attendance_pct=$a,extra_weights='$extraJson',grading_method='$method',base_grade=$base,midterm_weight=$mid,final_weight=$fin");
    echo json_encode(['success'=>true]);
    exit;
}

// ── Publish Grades ────────────────────────────────────────────────────────
if($action === 'publish_grades'){
    $term = $conn->real_escape_string($_POST['term'] ?? 'midterm');
    if(!in_array($term, ['midterm', 'final'])) $term = 'midterm';

    // Fetch weights
    $wq = $conn->query("SELECT * FROM class_record_weights WHERE class_id=$class_id");
    $weights = $wq->num_rows > 0 ? $wq->fetch_assoc() : [
        'written_pct'=>20,
        'performance_pct'=>40,
        'exam_pct'=>30,
        'attendance_pct'=>10,
        'grading_method'=>'sum_of_points',
        'base_grade'=>0,
        'midterm_weight'=>40,
        'final_weight'=>60,
        'extra_weights'=>'[]'
    ];
    if(!isset($weights['grading_method'])) $weights['grading_method'] = 'sum_of_points';
    if(!isset($weights['base_grade'])) $weights['base_grade'] = 0;
    if(!isset($weights['midterm_weight'])) $weights['midterm_weight'] = 40;
    if(!isset($weights['final_weight'])) $weights['final_weight'] = 60;
    if(!isset($weights['extra_weights'])) $weights['extra_weights'] = '[]';

    // Fetch all columns
    $colsQ = $conn->query("SELECT * FROM class_record_columns WHERE class_id=$class_id ORDER BY component,sort_order,id");
    $columns = [];
    while($r = $colsQ->fetch_assoc()) $columns[] = $r;
    
    $midtermCols = array_filter($columns, fn($c) => $c['term'] === 'midterm');
    $finalCols   = array_filter($columns, fn($c) => $c['term'] === 'final');

    $midtermColsByComp = ['written'=>[],'performance'=>[],'exam'=>[]];
    foreach($midtermCols as $col) $midtermColsByComp[$col['component']][] = $col;

    $finalColsByComp = ['written'=>[],'performance'=>[],'exam'=>[]];
    foreach($finalCols as $col) $finalColsByComp[$col['component']][] = $col;

    // Fetch scores
    $scoresQ = $conn->query("SELECT s.* FROM class_record_scores s JOIN class_record_columns col ON s.column_id=col.id WHERE col.class_id=$class_id");
    $scores = [];
    while($r = $scoresQ->fetch_assoc()) $scores[$r['column_id']][$r['student_code']] = $r['score'];

    // Fetch students
    $students = $conn->query("SELECT u.user_code, u.first_name, u.last_name FROM class_members cm JOIN users u ON cm.user_code=u.user_code WHERE cm.class_id=$class_id AND u.user_group='STUDENT'");

    function _transmute($grade) {
        if($grade === null) return '—';
        if($grade >= 99) return '1.00'; if($grade >= 96) return '1.25'; if($grade >= 93) return '1.50';
        if($grade >= 90) return '1.75'; if($grade >= 87) return '2.00'; if($grade >= 84) return '2.25';
        if($grade >= 81) return '2.50'; if($grade >= 78) return '2.75'; if($grade >= 75) return '3.00';
        return '5.00';
    }

    function _computeGrade($studentCode, $colsByComp, $scores, $weights) {
        $method = $weights['grading_method'] ?? 'sum_of_points';
        $base = (int)($weights['base_grade'] ?? 0);
        if ($base < 0 || $base >= 100) $base = 0;

        $compAvg = [];
        foreach(['written','performance','exam'] as $comp) {
            $cols = $colsByComp[$comp];
            $regularCols = array_filter($cols, fn($c) => empty($c['session_id']) && empty($c['is_f2f']));
            if(empty($regularCols)){ $compAvg[$comp] = null; }
            else {
                if ($method === 'avg_of_pct') {
                    $pcts = [];
                    foreach($regularCols as $col) {
                        $sc = $scores[$col['id']][$studentCode] ?? null;
                        if($sc !== null && $col['max_score'] > 0){
                            $pcts[] = ($sc / $col['max_score']) * 100;
                        }
                    }
                    $raw = count($pcts) ? (array_sum($pcts) / count($pcts)) : null;
                } else {
                    $total = 0; $max = 0; $hasAny = false;
                    foreach($regularCols as $col) {
                        $sc = $scores[$col['id']][$studentCode] ?? null;
                        if($sc !== null){ 
                            $total += $sc; 
                            $max += $col['max_score']; 
                            $hasAny = true; 
                        }
                    }
                    $raw = ($hasAny && $max > 0) ? ($total / $max) * 100 : null;
                }

                if ($raw !== null) {
                    $compAvg[$comp] = round($raw * (100 - $base) / 100 + $base, 2);
                } else {
                    $compAvg[$comp] = null;
                }
            }
        }

        $attCols = [];
        foreach($colsByComp as $comp => $cols) {
            foreach($cols as $col) {
                if(!empty($col['is_f2f']) || !empty($col['session_id']) || $comp === 'attendance' || ($col['component'] ?? '') === 'attendance') {
                    $attCols[] = $col;
                }
            }
        }
        if(!empty($attCols)) {
            if ($method === 'avg_of_pct') {
                $pcts = [];
                foreach($attCols as $col) {
                    $sc = $scores[$col['id']][$studentCode] ?? null;
                    if($sc !== null && $col['max_score'] > 0){
                        $pcts[] = ($sc / $col['max_score']) * 100;
                    }
                }
                $raw = count($pcts) ? (array_sum($pcts) / count($pcts)) : null;
            } else {
                $attTotal = 0; $attMax = 0; $attHas = false;
                foreach($attCols as $col) {
                    $sc = $scores[$col['id']][$studentCode] ?? null;
                    if($sc !== null){ 
                        $attTotal += $sc; 
                        $attMax += $col['max_score']; 
                        $attHas = true; 
                    }
                }
                $raw = ($attHas && $attMax > 0) ? ($attTotal / $attMax) * 100 : null;
            }

            if ($raw !== null) {
                $compAvg['attendance'] = round($raw * (100 - $base) / 100 + $base, 2);
            } else {
                $compAvg['attendance'] = null;
            }
        } else {
            $compAvg['attendance'] = null;
        }

        $wTotal = 0; $wWeight = 0;
        $compMap = [
            'written'    => 'written_pct',
            'performance'=> 'performance_pct',
            'exam'       => 'exam_pct',
            'attendance' => 'attendance_pct',
        ];
        foreach($compMap as $comp => $key) {
            if(isset($compAvg[$comp]) && $compAvg[$comp] !== null && isset($weights[$key])) {
                $wTotal  += $compAvg[$comp] * $weights[$key];
                $wWeight += $weights[$key];
            }
        }
        if(!empty($weights['extra_weights'])){
            $extraArr = json_decode($weights['extra_weights'], true);
            if(is_array($extraArr)){
                foreach($extraArr as $ew){
                    $wWeight += intval($ew['pct'] ?? 0);
                }
            }
        }
        return $wWeight > 0 ? round($wTotal / $wWeight, 2) : null;
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
            $rem   = $gradeVal >= 75 ? 'Passed' : 'Failed';
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
