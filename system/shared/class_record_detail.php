<?php
include '../includes/session.php';
include '../includes/conn.php';

$class_id = intval($_GET['id'] ?? 0);
$tc       = $conn->real_escape_string($user['user_code']);
$role     = strtoupper($user['user_group']);

if($role !== 'TEACHER'){
    // Bug 11 fix: was incorrectly redirecting to teacher dashboard for non-teachers
    header('location: ../student/dashboard.php'); exit;
}
if(!$class_id){ header('location: ../teacher/dashboard.php'); exit; }

$cq = $conn->query("SELECT c.*, u.first_name AS tf, u.last_name AS tl FROM classes c LEFT JOIN users u ON c.teacher_code=u.user_code WHERE c.id=$class_id AND c.teacher_code='$tc'");
if($cq->num_rows === 0){ die('Access denied.'); }
$class = $cq->fetch_assoc();

// Self-heal any student names in users table that contain concatenated email or section markers
$corrCheck = $conn->query("
    SELECT u.id, u.first_name, u.last_name 
    FROM class_members cm 
    JOIN users u ON cm.user_code = u.user_code 
    WHERE cm.class_id = $class_id 
      AND u.user_group = 'STUDENT' 
      AND (u.first_name LIKE '%@%' OR u.last_name LIKE '%@%' OR u.first_name REGEXP 'IS-[0-9]' OR u.last_name REGEXP 'IS-[0-9]' OR u.first_name LIKE '%Is-%' OR u.last_name LIKE '%Is-%')
");
if ($corrCheck && $corrCheck->num_rows > 0) {
    if (!function_exists('cleanStudentNameField')) {
        function cleanStudentNameField($str) {
            if (empty($str)) return '';
            $str = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/i', '', $str);
            $str = preg_replace('/\b\S+@\S*\b/i', '', $str);
            $str = preg_replace('/\b(?:BSIS|BSIT|BEED|BSED|BSCRIM|ARTS|BSOA|ACT|BLIS|BTVTED|BSNE|CS|IT)[-\s]?\d?[-\s]?[A-Z0-9]*\b/i', '', $str);
            $str = preg_replace('/\bIS[-\s]?\d*[-A-Z0-9]*\b/i', '', $str);
            $str = preg_replace('/\bIs-?\d*[-A-Z0-9]*\b/i', '', $str);
            $str = preg_replace('/\bSec(?:tion)?[-\s]?[A-Z0-9]+\b/i', '', $str);
            $str = preg_replace('/\bYr(?:ear)?[-\s]?\d+\b/i', '', $str);
            $str = preg_replace('/\bIs-?\b/i', '', $str);
            $str = preg_replace('/\bI\b$/i', '', $str);
            return trim(preg_replace('/\s+/', ' ', $str), " \t\n\r\0\x0B,-");
        }
    }
    while ($cRow = $corrCheck->fetch_assoc()) {
        $cid = (int)$cRow['id'];
        $cleanFn = cleanStudentNameField($cRow['first_name']);
        $cleanLn = cleanStudentNameField($cRow['last_name']);
        if (!empty($cleanFn) || !empty($cleanLn)) {
            $fnEsc = $conn->real_escape_string($cleanFn);
            $lnEsc = $conn->real_escape_string($cleanLn);
            $conn->query("UPDATE users SET first_name='$fnEsc', last_name='$lnEsc' WHERE id=$cid");
        }
    }
}

$students = $conn->query("SELECT u.user_code, u.first_name, u.middle_name, u.last_name, u.year_level, u.section, u.program_code FROM class_members cm LEFT JOIN users u ON cm.user_code=u.user_code WHERE cm.class_id=$class_id AND u.user_group='STUDENT' GROUP BY cm.user_code ORDER BY u.last_name, u.first_name");
$studentRows = [];
while($s = $students->fetch_assoc()) $studentRows[] = $s;

// ── Automatic Sync: Reconcile Quizzes, Assignments, Live Class Attendance ──
// 1. Sync Quizzes
$qzList = $conn->query("SELECT id, title, term FROM quizzes WHERE class_id=$class_id AND term != 'none'");
if ($qzList) {
    while ($qz = $qzList->fetch_assoc()) {
        $qid = intval($qz['id']);
        $qtitle = $conn->real_escape_string($qz['title']);
        $qterm = $conn->real_escape_string($qz['term']);
        
        // Compute max points
        $qptsQ = $conn->query("SELECT SUM(points) AS total FROM quiz_questions WHERE quiz_id=$qid");
        $qptsRow = $qptsQ ? $qptsQ->fetch_assoc() : null;
        $qmax = floatval($qptsRow['total'] ?? 0);
        if ($qmax <= 0) $qmax = 100;
        
        // Ensure column exists
        $colCheck = $conn->query("SELECT id FROM class_record_columns WHERE class_id=$class_id AND quiz_id=$qid LIMIT 1");
        if (!$colCheck || $colCheck->num_rows === 0) {
            $conn->query("INSERT INTO class_record_columns (class_id, component, title, max_score, sort_order, term, quiz_id)
                          VALUES ($class_id, 'written', '$qtitle', $qmax, 0, '$qterm', $qid)");
            $col_id = $conn->insert_id;
        } else {
            $col_id = intval($colCheck->fetch_assoc()['id']);
            $conn->query("UPDATE class_record_columns SET max_score=$qmax, title='$qtitle', term='$qterm' WHERE id=$col_id");
        }
        
        // Sync student submissions for this quiz
        $subsQ = $conn->query("SELECT student_code, score, total_points FROM quiz_submissions WHERE quiz_id=$qid");
        if ($subsQ) {
            while ($sub = $subsQ->fetch_assoc()) {
                $sub_uc = $conn->real_escape_string($sub['student_code']);
                $sub_score = floatval($sub['score']);
                $sub_total = floatval($sub['total_points']);
                $scaledScore = ($sub_total > 0 && $qmax > 0) ? round(($sub_score / $sub_total) * $qmax, 2) : $sub_score;
                $conn->query("INSERT INTO class_record_scores (column_id, class_id, student_code, score)
                              VALUES ($col_id, $class_id, '$sub_uc', $scaledScore)
                              ON DUPLICATE KEY UPDATE score = $scaledScore");
            }
        }
    }
}

// 2. Sync Assignments
$asList = $conn->query("SELECT id, title, points, term FROM assignments WHERE class_id=$class_id AND term != 'none'");
if ($asList) {
    while ($as = $asList->fetch_assoc()) {
        $aid = intval($as['id']);
        $atitle = $conn->real_escape_string($as['title']);
        $aterm = $conn->real_escape_string($as['term']);
        $amax = floatval($as['points']);
        if ($amax <= 0) $amax = 100;
        
        // Ensure column exists
        $colCheck = $conn->query("SELECT id FROM class_record_columns WHERE class_id=$class_id AND assignment_id=$aid LIMIT 1");
        if (!$colCheck || $colCheck->num_rows === 0) {
            $conn->query("INSERT INTO class_record_columns (class_id, component, title, max_score, sort_order, term, assignment_id)
                          VALUES ($class_id, 'performance', '$atitle', $amax, 0, '$aterm', $aid)");
            $col_id = $conn->insert_id;
        } else {
            $col_id = intval($colCheck->fetch_assoc()['id']);
            $conn->query("UPDATE class_record_columns SET max_score=$amax, title='$atitle', term='$aterm' WHERE id=$col_id");
        }
        
        // Sync student submissions for this assignment (graded only)
        $subsQ = $conn->query("SELECT student_code, grade FROM assignment_submissions WHERE assignment_id=$aid AND grade IS NOT NULL");
        if ($subsQ) {
            while ($sub = $subsQ->fetch_assoc()) {
                $sub_uc = $conn->real_escape_string($sub['student_code']);
                $sub_grade = floatval($sub['grade']);
                $conn->query("INSERT INTO class_record_scores (column_id, class_id, student_code, score)
                              VALUES ($col_id, $class_id, '$sub_uc', $sub_grade)
                              ON DUPLICATE KEY UPDATE score = $sub_grade");
            }
        }
    }
}

// 3. Sync Live Sessions
$lsList = $conn->query("SELECT id, title, term, started_at, scheduled_at FROM live_sessions WHERE class_id=$class_id");
if ($lsList) {
    while ($ls = $lsList->fetch_assoc()) {
        $sid = intval($ls['id']);
        $lsterm = $conn->real_escape_string($ls['term'] ?: 'midterm');
        $rawDate = $ls['started_at'] ?: $ls['scheduled_at'];
        if(!$rawDate && !empty($ls['title']) && strtotime($ls['title'])){
            $rawDate = $ls['title'];
        }
        $dateStr = date('M d', strtotime($rawDate ?: 'now'));
        $lstitle = $conn->real_escape_string($dateStr);
        $sessCreated = date('Y-m-d H:i:s', strtotime($rawDate ?: 'now'));
        
        // Ensure column exists
        $colCheck = $conn->query("SELECT id FROM class_record_columns WHERE class_id=$class_id AND session_id=$sid AND (is_f2f=0 OR is_f2f IS NULL) LIMIT 1");
        if (!$colCheck || $colCheck->num_rows === 0) {
            $conn->query("INSERT INTO class_record_columns (class_id, component, title, max_score, sort_order, term, session_id, is_f2f, created_at)
                          VALUES ($class_id, 'attendance', '$lstitle', 2.00, 0, '$lsterm', $sid, 0, '$sessCreated')");
            $col_id = $conn->insert_id;
        } else {
            $col_id = intval($colCheck->fetch_assoc()['id']);
            $conn->query("UPDATE class_record_columns SET title='$lstitle', term='$lsterm', max_score = 2.00, is_f2f=0 WHERE id=$col_id");
        }
        
        // Sync student attendance
        $attQ = $conn->query("SELECT student_code, joined_at FROM live_attendance WHERE session_id=$sid");
        if ($attQ) {
            $sessStart = $ls['started_at'] ?: $ls['scheduled_at'] ?: 'now';
            $startedTime = strtotime($sessStart);
            while ($att = $attQ->fetch_assoc()) {
                $att_uc = $conn->real_escape_string($att['student_code']);
                
                $joinedTime = strtotime($att['joined_at']);
                $diffMinutes = ($joinedTime - $startedTime) / 60;
                
                $score = 2.00;
                if ($diffMinutes >= 15) {
                    $score = 1.00;
                }
                
                $conn->query("INSERT INTO class_record_scores (column_id, class_id, student_code, score)
                              VALUES ($col_id, $class_id, '$att_uc', $score)
                              ON DUPLICATE KEY UPDATE score = GREATEST(COALESCE(score, 0), $score)");
            }
        }
    }
}

// 4. Sync Attendance Sessions (Calendar & Matrix Attendance)
$casList = $conn->query("SELECT id, title, attendance_date, term FROM class_attendance_sessions WHERE class_id=$class_id");
if ($casList) {
    while ($cas = $casList->fetch_assoc()) {
        $cas_id = intval($cas['id']);
        $cas_term = $conn->real_escape_string($cas['term'] ?: 'midterm');
        $cas_date = $cas['attendance_date'];
        $cas_created = $cas_date . ' 00:00:00';
        $dateStr = date('M d', strtotime($cas_date));
        $castitle = $conn->real_escape_string($dateStr);
        
        // Ensure column exists in class_record_columns
        $colCheck = $conn->query("SELECT id FROM class_record_columns WHERE class_id=$class_id AND (attendance_session_id=$cas_id OR (is_f2f=1 AND session_id=$cas_id) OR (is_f2f=1 AND created_at LIKE '$cas_date%')) LIMIT 1");
        if (!$colCheck || $colCheck->num_rows === 0) {
            $conn->query("INSERT INTO class_record_columns (class_id, component, title, max_score, sort_order, term, session_id, attendance_session_id, is_f2f, created_at)
                          VALUES ($class_id, 'attendance', '$castitle', 1.00, 0, '$cas_term', $cas_id, $cas_id, 1, '$cas_created')");
            $col_id = $conn->insert_id;
        } else {
            $col_id = intval($colCheck->fetch_assoc()['id']);
            $conn->query("UPDATE class_record_columns SET title='$castitle', term='$cas_term', session_id=$cas_id, attendance_session_id=$cas_id, is_f2f=1, created_at='$cas_created' WHERE id=$col_id");
        }
        
        // Sync individual records
        $recsQ = $conn->query("SELECT student_code, status FROM class_attendance_records WHERE session_id=$cas_id");
        if ($recsQ) {
            while ($rec = $recsQ->fetch_assoc()) {
                $rec_uc = $conn->real_escape_string($rec['student_code']);
                $st = strtolower($rec['status']);
                $score = 1.00;
                if ($st === 'late') $score = 0.50;
                if ($st === 'absent') $score = 0.00;
                if ($st === 'excused') $score = 1.00;
                
                $conn->query("INSERT INTO class_record_scores (column_id, class_id, student_code, score)
                              VALUES ($col_id, $class_id, '$rec_uc', $score)
                              ON DUPLICATE KEY UPDATE score = $score");
            }
        }
    }
}

$term = $_GET['term'] ?? 'midterm';
if(!in_array($term, ['midterm', 'final'])) $term = 'midterm';

// Load all columns for both terms
$allColsQ = $conn->query("SELECT col.*,
       q.created_at AS quiz_created,
       a.created_at AS assign_created,
       ls.started_at AS session_started,
       ls.scheduled_at AS session_scheduled,
       ls.title AS session_title,
       cas.attendance_date AS attendance_date
FROM class_record_columns col
LEFT JOIN quizzes q ON col.quiz_id = q.id
LEFT JOIN assignments a ON col.assignment_id = a.id
LEFT JOIN live_sessions ls ON (col.session_id = ls.id AND (col.is_f2f = 0 OR col.is_f2f IS NULL))
LEFT JOIN class_attendance_sessions cas ON (col.attendance_session_id = cas.id OR (col.is_f2f = 1 AND col.session_id = cas.id))
WHERE col.class_id=$class_id
ORDER BY col.component, col.sort_order, col.id");
$allColumns = [];
while($r = $allColsQ->fetch_assoc()) $allColumns[] = $r;

// Separate columns by term
$midtermCols = array_filter($allColumns, fn($c) => $c['term'] === 'midterm');
$finalCols = array_filter($allColumns, fn($c) => $c['term'] === 'final');
$columns = $term === 'midterm' ? $midtermCols : $finalCols;

$scoresQ = $conn->query("SELECT s.* FROM class_record_scores s JOIN class_record_columns col ON s.column_id=col.id WHERE col.class_id=$class_id");
$scores = [];
while($r = $scoresQ->fetch_assoc()) $scores[$r['column_id']][$r['student_code']] = $r['score'];

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
// Normalize column name differences between old and new schema
if(!isset($weights['written_pct']) && isset($weights['written_works_pct']))   $weights['written_pct'] = $weights['written_works_pct'];
if(!isset($weights['exam_pct'])    && isset($weights['term_exam_pct']))        $weights['exam_pct']    = $weights['term_exam_pct'];

// Organize columns by component for current term
$colsByComp = ['written'=>[],'performance'=>[],'exam'=>[],'deportment'=>[],'attendance'=>[]];
foreach($columns as $col) {
    if($col['component'] === 'deportment') {
        $colsByComp['deportment'][] = $col;
    } elseif(!empty($col['session_id']) || $col['component'] === 'attendance') {
        $colsByComp['attendance'][] = $col;
    } else {
        $compKey = $col['component'];
        if(!isset($colsByComp[$compKey])) $colsByComp[$compKey] = [];
        $colsByComp[$compKey][] = $col;
    }
}

// Organize columns by component for both terms
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

if(!function_exists('computeGrade')):
function computeGrade($studentCode, $colsByComp, $scores, $weights) {
    $method = $weights['grading_method'] ?? 'sum_of_points';
    $base = (int)($weights['base_grade'] ?? 0);
    if ($base < 0 || $base >= 100) $base = 0;

    $compAvg = [];
    foreach(['written','performance','exam','deportment'] as $comp) {
        $cols = $colsByComp[$comp] ?? [];
        $regularCols = array_filter($cols, fn($c) => empty($c['session_id']));
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

    // Attendance average from attendance columns
    $attCols = $colsByComp['attendance'] ?? [];
    if(!empty($attCols)) {
        $attTotal = 0; $attMax = 0; $attHas = false;
        foreach($attCols as $col) {
            $sc = $scores[$col['id']][$studentCode] ?? null;
            if($sc !== null){ 
                $attTotal += $sc; 
                $attMax += $col['max_score']; 
                $attHas = true; 
            }
        }
        $compAvg['attendance'] = ($attHas && $attMax > 0) ? round(($attTotal / $attMax) * 100, 1) : null;
    } else {
        $compAvg['attendance'] = null;
    }

    // Weighted final grade
    $wTotal = 0; $wWeight = 0;
    $compMap = [
        'written'    => 'written_pct',
        'performance'=> 'performance_pct',
        'exam'       => 'exam_pct',
        'deportment' => 'attendance_pct', // Maps to deportment weight (10%)
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
    $final = $wWeight > 0 ? round($wTotal / $wWeight, 2) : null;
    return ['components'=>$compAvg,'final'=>$final];
}
endif;

$studentGrades = [];
$studentMidtermGrades = [];
$studentFinalGrades = [];
$studentOverallGrades = [];

$midPct = floatval($weights['midterm_weight'] ?? 40) / 100;
$finPct = floatval($weights['final_weight'] ?? 60) / 100;

foreach($studentRows as $s) {
    $studentGrades[$s['user_code']] = computeGrade($s['user_code'], $colsByComp, $scores, $weights);
    $studentMidtermGrades[$s['user_code']] = computeGrade($s['user_code'], $midtermColsByComp, $scores, $weights);
    $studentFinalGrades[$s['user_code']] = computeGrade($s['user_code'], $finalColsByComp, $scores, $weights);
    
    // Calculate overall grade using custom term weights
    $midVal = $studentMidtermGrades[$s['user_code']]['final'];
    $finVal = $studentFinalGrades[$s['user_code']]['final'];
    
    if ($midVal !== null && $finVal !== null) {
        $studentOverallGrades[$s['user_code']] = round(($midVal * $midPct) + ($finVal * $finPct), 2);
    } elseif ($midVal !== null) {
        $studentOverallGrades[$s['user_code']] = $midVal;
    } elseif ($finVal !== null) {
        $studentOverallGrades[$s['user_code']] = $finVal;
    } else {
        $studentOverallGrades[$s['user_code']] = null;
    }
}

if(!function_exists('transmute')):
function transmute($grade) {
    if($grade === null) return '—';
    if($grade >= 99) return '1.00'; if($grade >= 96) return '1.25'; if($grade >= 93) return '1.50';
    if($grade >= 90) return '1.75'; if($grade >= 87) return '2.00'; if($grade >= 84) return '2.25';
    if($grade >= 81) return '2.50'; if($grade >= 78) return '2.75'; if($grade >= 75) return '3.00';
    return '5.00';
}
endif;
if(!function_exists('gradeStatus')):
function gradeStatus($grade) {
    if($grade === null) return ['—','#94a3b8','#f1f5f9'];
    if($grade >= 75) return ['Passed','#166534','#dcfce7'];
    return ['Failed','#991b1b','#fee2e2'];
}
endif;

// KPI cards reflect active term grades or overall fallback
$activeTermGradesMap = $term === 'midterm' ? $studentMidtermGrades : $studentFinalGrades;
$activeTermGradesArr = [];
foreach($activeTermGradesMap as $uc => $gData) {
    if(isset($gData['final']) && $gData['final'] !== null) {
        $activeTermGradesArr[] = $gData['final'];
    }
}
$finals   = !empty($activeTermGradesArr) ? $activeTermGradesArr : array_filter($studentOverallGrades, fn($v) => $v !== null);
$classAvg = count($finals) ? round(array_sum($finals)/count($finals),1) : null;
$passing  = count(array_filter($finals, fn($v) => $v >= 75));
$failing  = count(array_filter($finals, fn($v) => $v < 75));
$topScore = count($finals) ? max($finals) : null;
$lowScore = count($finals) ? min($finals) : null;

// Attendance from live sessions
$attendanceData = [];
$sessionsQ = $conn->query("SELECT ls.id, ls.title, ls.started_at FROM live_sessions ls WHERE ls.class_id=$class_id AND ls.status='ended' ORDER BY ls.started_at ASC");
$liveSessions = [];
while($ls = $sessionsQ->fetch_assoc()) $liveSessions[] = $ls;
foreach($liveSessions as $ls) {
    $attQ = $conn->query("SELECT student_code FROM live_attendance WHERE session_id={$ls['id']}");
    while($a = $attQ->fetch_assoc()) $attendanceData[$ls['id']][$a['student_code']] = true;
}

// Quiz scores
$quizzesQ = $conn->query("SELECT q.id, q.title, q.due_date FROM quizzes q WHERE q.class_id=$class_id ORDER BY q.created_at ASC");
$quizList = [];
while($q = $quizzesQ->fetch_assoc()) $quizList[] = $q;
$quizScores = [];
foreach($quizList as $qz) {
    $subQ = $conn->query("SELECT student_code, score, total_points FROM quiz_submissions WHERE quiz_id={$qz['id']}");
    while($s = $subQ->fetch_assoc()) $quizScores[$qz['id']][$s['student_code']] = $s;
}

// Assignment scores
$assignQ = $conn->query("SELECT a.id, a.title, a.points, a.due_date FROM assignments a WHERE a.class_id=$class_id ORDER BY a.created_at ASC");
$assignList = [];
while($a = $assignQ->fetch_assoc()) $assignList[] = $a;
$assignScores = [];
foreach($assignList as $as) {
    $subQ = $conn->query("SELECT student_code, grade FROM assignment_submissions WHERE assignment_id={$as['id']}");
    while($s = $subQ->fetch_assoc()) $assignScores[$as['id']][$s['student_code']] = $s['grade'];
}

// Categorize columns for active term
$termAttendanceCols = [];
$termDeportmentCols = [];
$termQuizCols = [];
$termAssignCols = [];
$termExamCols = [];
foreach($columns as $col) {
    if($col['component'] === 'deportment') {
        $termDeportmentCols[] = $col;
    } elseif(!empty($col['session_id']) || $col['component'] === 'attendance') {
        $termAttendanceCols[] = $col;
    } elseif($col['component'] === 'written') {
        $termQuizCols[] = $col;
    } elseif($col['component'] === 'performance') {
        $termAssignCols[] = $col;
    } elseif($col['component'] === 'exam') {
        $termExamCols[] = $col;
    }
}

// Pre-compute Attendance KPIs based on active term attendance columns
$totalSessions = count($termAttendanceCols);
$attPcts = [];
$perfectAtt = 0;
foreach($studentRows as $s) {
    $uc = $s['user_code'];
    $attAvg = $studentGrades[$uc]['components']['attendance'] ?? null;
    if($attAvg !== null) {
        $attPcts[] = $attAvg;
        if($attAvg >= 100) $perfectAtt++;
    }
}
$avgAttPct = count($attPcts) ? round(array_sum($attPcts)/count($attPcts),1) : null;

// Pre-compute quiz KPIs
$totalQuizzes = count($quizList);
$quizAvgPcts = [];
foreach($studentRows as $s) {
    $uc = $s['user_code']; $pcts = [];
    foreach($quizList as $qz) {
        $sub = $quizScores[$qz['id']][$uc] ?? null;
        if($sub && $sub['total_points'] > 0) $pcts[] = $sub['score']/$sub['total_points']*100;
    }
    if(count($pcts)) $quizAvgPcts[] = round(array_sum($pcts)/count($pcts),1);
}
$avgQuizPct = count($quizAvgPcts) ? round(array_sum($quizAvgPcts)/count($quizAvgPcts),1) : null;

// Pre-compute assignment KPIs
$totalAssignments = count($assignList);
$assignAvgPcts = [];
foreach($studentRows as $s) {
    $uc = $s['user_code']; $pcts = [];
    foreach($assignList as $as) {
        $grade = $assignScores[$as['id']][$uc] ?? null;
        if($grade !== null && $as['points'] > 0) $pcts[] = $grade/$as['points']*100;
    }
    if(count($pcts)) $assignAvgPcts[] = round(array_sum($pcts)/count($pcts),1);
}
$avgAssignPct = count($assignAvgPcts) ? round(array_sum($assignAvgPcts)/count($assignAvgPcts),1) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Class Record — <?php echo htmlspecialchars($class['class_name']); ?></title>
  <link rel="stylesheet" href="../bower_components/bootstrap/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../bower_components/font-awesome/css/font-awesome.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../dist/css/cenlearn.css">
  <style>
    *{box-sizing:border-box;}
    .t-sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:linear-gradient(180deg,#0f2027 0%,#203a43 55%,#2c5364 100%);display:flex;flex-direction:column;z-index:200;transition:transform .3s cubic-bezier(.4,0,.2,1);transform:translateX(-260px);}
    .t-sidebar.open{transform:translateX(0);}
    @media(min-width: 901px) { .t-sidebar{transform:translateX(0);} }
    .sb-brand{padding:22px 20px 16px;border-bottom:1px solid rgba(255,255,255,.07);}
    .sb-logo{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#10b981,#059669);display:inline-flex;align-items:center;justify-content:center;margin-bottom:8px;box-shadow:0 4px 14px rgba(16,185,129,.4);}
    .sb-logo i{color:#fff;font-size:17px;}
    .sb-brand h2{color:#fff;font-size:18px;font-weight:800;margin:0;}
    .sb-brand h2 span{color:#10b981;}
    .sb-brand p{color:rgba(255,255,255,.3);font-size:10px;margin:2px 0 0;}
    .sb-nav{flex:1;padding:10px 0;overflow-y:auto;}
    .sb-nav-sec{padding:10px 20px 4px;font-size:9px;font-weight:700;color:rgba(255,255,255,.22);letter-spacing:1.5px;text-transform:uppercase;}
    .sb-nav ul{list-style:none;margin:0;padding:0;}
    .sb-nav li a{display:flex;align-items:center;gap:10px;padding:10px 20px;color:rgba(255,255,255,.55);text-decoration:none;font-size:13px;font-weight:500;transition:all .18s;border-left:3px solid transparent;}
    .sb-nav li a:hover{background:rgba(255,255,255,.06);color:#fff;}
    .sb-nav li.active a{background:rgba(16,185,129,.15);color:#fff;border-left-color:#10b981;}
    .sb-nav li a i{width:16px;text-align:center;font-size:13px;}
    .sb-footer{padding:14px 20px;border-top:1px solid rgba(255,255,255,.07);}
    .sb-user{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
    .sb-av{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#10b981,#059669);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;flex-shrink:0;}
    .sb-meta strong{display:block;color:#fff;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;}
    .sb-meta span{color:rgba(255,255,255,.38);font-size:10px;}
    .sb-out{display:flex;align-items:center;justify-content:center;gap:6px;padding:8px;width:100%;background:rgba(255,255,255,.06);color:rgba(255,255,255,.55);border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:12px;font-weight:500;text-decoration:none;transition:all .18s;}
    .sb-out:hover{background:rgba(255,255,255,.12);color:#fff;}
    .sb-submenu{list-style:none;padding:0;margin:0;background:rgba(0,0,0,0.15);border-left:3px solid rgba(16,185,129,0.3);}
    .sb-submenu li a{padding:8px 20px 8px 40px !important;font-size:12px !important;color:rgba(255, 255, 255, 0.6) !important;border-left:none !important;}
    .sb-submenu li a:hover{color:#fff !important;background:rgba(255,255,255,0.05) !important;}
    .sb-submenu li.active a{color:#fff !important;background:rgba(16,185,129,0.15) !important;font-weight:700;}
    @media(min-width: 901px) { .cr-wrap{margin-left:260px !important;} }
    html,body{margin:0;padding:0;overflow-x:hidden;}
    body{font-family:'Inter',sans-serif;background:#f0f4f8;margin:0;color:#1e293b;}
    .cr-wrap{margin-left:0;min-height:100vh;display:flex;flex-direction:column;transition:margin 0s;}
    @media(min-width: 901px) {
      .cr-wrap{margin-left:260px;}
    }
    .cr-topbar{background:#fff;padding:0 20px;height:60px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:50;box-shadow:0 1px 4px rgba(0,0,0,.04);gap:10px;flex-wrap:wrap;}
    .cr-topbar h3{font-size:14px;font-weight:700;color:#0f172a;margin:0;}
    .cr-topbar p{font-size:11px;color:#64748b;margin:0;}
    .cr-content{padding:16px 20px 40px;flex:1;}

    /* Tab switcher */
    .tab-switcher{display:flex;gap:4px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:5px;margin-bottom:16px;overflow-x:auto;flex-shrink:0;}
    .tab-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:none;background:transparent;border-radius:8px;font-size:12px;font-weight:600;color:#64748b;cursor:pointer;font-family:'Inter',sans-serif;white-space:nowrap;transition:all .15s;}
    .tab-btn:hover{background:#f1f5f9;color:#0f172a;}
    .tab-btn.active{background:linear-gradient(135deg,#10b981,#059669);color:#fff;box-shadow:0 2px 8px rgba(16,185,129,.35);}
    .tab-btn i{font-size:13px;}
    .tab-panel{display:none;}
    .tab-panel.active{display:block;}

    /* KPI */
    .kpi-row{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:16px;}
    .kpi-row-4{grid-template-columns:repeat(4,1fr);}
    .kpi-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;text-align:center;min-height:80px;}
    .kpi-card strong{display:block;font-size:20px;font-weight:800;line-height:1;margin-bottom:2px;}
    .kpi-card span{font-size:10px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.4px;}

    /* Weights */
    .weights-bar{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;min-height:56px;}
    .weights-bar label{font-size:12px;font-weight:600;color:#374151;display:flex;align-items:center;gap:6px;}
    .weights-bar input[type=number]{width:56px;padding:5px 7px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;font-family:'Inter',sans-serif;text-align:center;}
    .weights-bar input[type=number]:focus{outline:none;border-color:#10b981;}

    /* Table shared */
    .cr-table-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;}
    .cr-table-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;position:relative;}
    table.cr-table{border-collapse:collapse;min-width:100%;font-size:12px;}
    .cr-table th,.cr-table td{border:1px solid #e8edf2;padding:0;white-space:nowrap;}
    .cr-table th{background:#f8fafc;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;padding:7px 9px;text-align:center;position:sticky;top:0;z-index:2;}
    .cr-table td{padding:5px 7px;text-align:center;color:#334155;}
    .cr-table tbody tr:hover td{background:#f8fafc;}
    .col-no{width:32px;min-width:32px;}
    .col-name{min-width:170px;text-align:left !important;font-weight:600;color:#0f172a;padding-left:10px !important;position:sticky;left:0;z-index:3;background:#fff;}
    .cr-table th.col-name{z-index:4;background:#f8fafc;}
    .cr-table tbody tr:hover td.col-name{background:#f8fafc;}
    .comp-hdr-written{background:#dbeafe;color:#1d4ed8;}
    .comp-hdr-performance{background:#dcfce7;color:#166534;}
    .comp-hdr-exam{background:#ede9fe;color:#5b21b6;}
    .comp-hdr-grade{background:#fef3c7;color:#92400e;}
    .score-input{width:50px;border:none;background:transparent;text-align:center;font-size:12px;font-family:'Inter',sans-serif;color:#0f172a;padding:4px;}
    .score-input:focus{outline:2px solid #10b981;border-radius:4px;background:#f0fdf4;}
    .grade-cell{font-weight:700;font-size:12px;}
    .grade-pass{color:#166534;} .grade-fail{color:#991b1b;}

    /* Attendance cells */
    .att-present{background:#dcfce7;color:#166534;font-weight:700;font-size:13px;}
    .att-absent{background:#fee2e2;color:#991b1b;font-weight:700;font-size:13px;}
    .att-pct-high{color:#166534;font-weight:700;}
    .att-pct-low{color:#991b1b;font-weight:700;}

    /* Quiz/Assignment cells */
    .score-high{background:#dcfce7;color:#166534;font-weight:600;}
    .score-low{background:#fee2e2;color:#991b1b;font-weight:600;}
    .score-none{background:#f1f5f9;color:#94a3b8;font-style:italic;}

    /* Buttons */
    .btn-add-col{display:inline-flex;align-items:center;gap:4px;padding:4px 9px;border:1.5px dashed #10b981;background:transparent;color:#10b981;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;white-space:nowrap;}
    .btn-add-col:hover{background:#f0fdf4;}
    .btn-del-col{background:none;border:none;color:#ef4444;cursor:pointer;font-size:10px;padding:1px 3px;opacity:.5;}
    .btn-del-col:hover{opacity:1;}
    .btn-green{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;transition:opacity .2s;}
    .btn-green:hover{opacity:.88;}
    .btn-ghost-sm{display:inline-flex;align-items:center;gap:4px;padding:6px 11px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-size:11px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;}
    .btn-ghost-sm:hover{background:#e2e8f0;}
    .btn-export{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;background:#fff;color:#475569;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;transition:all .15s;}
    .btn-export:hover{border-color:#10b981;color:#10b981;}

    /* Tab toolbar */
    .tab-toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;}
    .tab-toolbar-left{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}

    /* Empty state */
    .empty-state{text-align:center;padding:48px;color:#94a3b8;}
    .empty-state i{font-size:36px;display:block;margin-bottom:12px;opacity:.4;}
    .empty-state p{margin:0;font-size:14px;}

    .cr-section-title {
      font-size: 13px;
      font-weight: 700;
      color: #0f172a;
      margin: 24px 0 10px 0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 2px solid #e2e8f0;
      padding-bottom: 6px;
    }
    .cr-section-title i {
      margin-right: 6px;
    }
    .btn-f2f {
      border: none;
      border-radius: 6px;
      padding: 3px 8px;
      font-size: 11px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.15s ease;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      margin: 2px 0;
    }
    .btn-f2f.present {
      background-color: #dcfce7;
      color: #15803d;
    }
    .btn-f2f.present:hover {
      background-color: #bbf7d0;
    }
    .btn-f2f.absent {
      background-color: #fee2e2;
      color: #b91c1c;
    }
    .btn-f2f.absent:hover {
      background-color: #fecaca;
    }
    .btn-f2f.unrecorded {
      background-color: #f1f5f9;
      color: #64748b;
    }
    .btn-f2f.unrecorded:hover {
      background-color: #e2e8f0;
    }

    /* Modal */
    .cr-modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;z-index:1000;opacity:0;pointer-events:none;transition:opacity .2s;backdrop-filter:blur(4px);}
    .cr-modal-overlay.open{opacity:1;pointer-events:all;}
    .cr-modal{background:#fff;border-radius:16px;width:100%;max-width:380px;margin:16px;box-shadow:0 24px 64px rgba(0,0,0,.2);transform:translateY(16px);transition:transform .2s;overflow:hidden;}
    .cr-modal-overlay.open .cr-modal{transform:translateY(0);}
    .cr-modal-head{padding:14px 18px;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#10b981,#059669);}
    .cr-modal-head h4{color:#fff;font-size:14px;font-weight:700;margin:0;}
    .cr-modal-x{width:26px;height:26px;border-radius:7px;border:none;cursor:pointer;background:rgba(255,255,255,.2);color:#fff;font-size:14px;display:flex;align-items:center;justify-content:center;}
    .cr-modal-body{padding:18px;}
    .cr-field{margin-bottom:12px;}
    .cr-field label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;}
    .cr-fc{width:100%;padding:9px 11px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:'Inter',sans-serif;background:#f9fafb;}
    .cr-fc:focus{outline:none;border-color:#10b981;}
    .cr-modal-foot{padding:12px 18px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:8px;}

    /* Responsive */
    @media(max-width:1200px){
      .kpi-row{grid-template-columns:repeat(3,1fr);}
    }
    @media(max-width:768px){
      .cr-wrap{margin-left:0;}
      .cr-content{padding:10px;}
      .kpi-row{grid-template-columns:repeat(2,1fr);}
      .cr-topbar{height:auto;padding:10px 12px;}
      .cr-topbar-actions{flex-wrap:wrap;gap:6px;}
      .tab-switcher{border-radius:8px;}
    }
    @media(max-width:480px){
      .kpi-row{grid-template-columns:1fr;}
    }
  </style>
</head>
<body>
<div class="cl-sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<?php 
  $initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
?>
<aside class="t-sidebar" id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo"><i class="fa fa-graduation-cap"></i></div>
    <h2>Cen<span>Learn</span></h2>
    <p>Learning Management System</p>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-sec">Main</div>
    <ul>
      <li><a href="../teacher/dashboard.php"><i class="fa fa-th-large"></i> Dashboard</a></li>
      <li class="active">
        <a href="../teacher/classes.php"><i class="fa fa-book"></i> Classes</a>
        <ul class="sb-submenu" id="classSubmenu" style="display: block;">
          <li><a href="class_view.php?id=<?php echo $class_id;?>&tab=materials" id="subMaterials"><i class="fa fa-folder-open"></i> Materials</a></li>
          <li><a href="class_view.php?id=<?php echo $class_id;?>&tab=classwork" id="subClasswork"><i class="fa fa-tasks"></i> Classwork</a></li>
          <li><a href="live_class.php?id=<?php echo $class_id;?>" id="subLiveClass"><i class="fa fa-video-camera"></i> Live Class</a></li>
          <li><a href="class_view.php?id=<?php echo $class_id;?>&tab=performance" id="subPerformance"><i class="fa fa-line-chart"></i> Performance &amp; Analytics</a></li>
          <li class="active"><a href="class_record_detail.php?id=<?php echo $class_id;?>" id="subRecord"><i class="fa fa-book"></i> Subject Class Record</a></li>
        </ul>
      </li>
      <li><a href="../teacher/quizzes.php"><i class="fa fa-question-circle"></i> Quizzes</a></li>
      <li><a href="../teacher/assignments.php"><i class="fa fa-tasks"></i> Assignments</a></li>
      <li><a href="../teacher/attendance.php"><i class="fa fa-calendar-check-o"></i> Attendance</a></li>
      <li><a href="../teacher/logbook.php"><i class="fa fa-pencil-square-o"></i> Manage Subject</a></li>
      <li><a href="../teacher/class_record.php"><i class="fa fa-table"></i> Class Record</a></li>
    </ul>
  </nav>
  <div class="sb-footer">
    <div class="sb-user">
      <div class="sb-av"><?php echo $initials; ?></div>
      <div class="sb-meta">
        <strong><?php echo htmlspecialchars($user['first_name'].' '.$user['last_name']); ?></strong>
        <span>Teacher</span>
      </div>
    </div>
    <a href="../logout.php" class="sb-out"><i class="fa fa-sign-out"></i> Sign Out</a>
  </div>
</aside>

<div class="cr-wrap">
  <header class="cr-topbar">
    <div style="display:flex;align-items:center;gap:10px;min-width:0;">
      <button class="cl-hamburger" onclick="openSidebar()" aria-label="Menu"><i class="fa fa-bars"></i></button>
      <div style="min-width:0;">
        <h3><?php echo htmlspecialchars($class['class_name']); ?> — Class Record</h3>
        <p><?php echo htmlspecialchars($class['subject']?:''); ?><?php if($class['section']): ?> &bull; Sec <?php echo htmlspecialchars($class['section']); ?><?php endif; ?> &bull; <?php echo count($studentRows); ?> students</p>
      </div>
    </div>
    <div class="cr-topbar-actions" style="display:flex;align-items:center;gap:6px;flex-shrink:0;">
      <button class="btn-green" id="btnSaveWeights" onclick="saveWeights()"><i class="fa fa-save"></i> Save Weights</button>
      <button class="btn-green" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);box-shadow:0 2px 8px rgba(59,130,246,.35);" onclick="publishTermGrades()"><i class="fa fa-paper-plane"></i> Send Grades to Students</button>
    </div>
  </header>

  <div class="cr-content">

    <!-- Tab Switcher -->
    <div class="tab-switcher">
      <button class="tab-btn active" onclick="switchTab('tab-record',this)"><i class="fa fa-table"></i> Class Record</button>
    </div>

    <!-- ===== TAB 1: CLASS RECORD ===== -->
    <div class="tab-panel active" id="tab-record">

      <!-- Term Toggle Row -->
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;gap:4px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:4px;">
          <a href="?id=<?php echo $class_id; ?>&term=midterm" class="tab-btn <?php echo $term==='midterm'?'active':''; ?>" style="<?php echo $term==='midterm'?'background:linear-gradient(135deg,#10b981,#059669);color:#fff;box-shadow:0 2px 6px rgba(16,185,129,.3);':''; ?>padding:6px 14px;font-size:12px;font-weight:700;border-radius:8px;text-decoration:none;"><i class="fa fa-clock-o"></i> Midterm Term</a>
          <a href="?id=<?php echo $class_id; ?>&term=final" class="tab-btn <?php echo $term==='final'?'active':''; ?>" style="<?php echo $term==='final'?'background:linear-gradient(135deg,#10b981,#059669);color:#fff;box-shadow:0 2px 6px rgba(16,185,129,.3);':''; ?>padding:6px 14px;font-size:12px;font-weight:700;border-radius:8px;text-decoration:none;"><i class="fa fa-clock-o"></i> Final Term</a>
        </div>
        <div style="font-size:12px;color:#64748b;font-weight:600;display:flex;align-items:center;gap:6px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:8px 14px;">
          <span style="width:8px;height:8px;border-radius:50%;background:#10b981;display:inline-block;"></span>
          Active Period: <strong><?php echo ucfirst($term); ?> grading</strong>
        </div>
      </div>

      <!-- KPI Row -->
      <div class="kpi-row">
        <div class="kpi-card"><strong style="color:#10b981;"><?php echo $classAvg !== null ? $classAvg.'%' : '—'; ?></strong><span>Class Avg</span></div>
        <div class="kpi-card"><strong style="color:#1d4ed8;"><?php echo $topScore !== null ? $topScore.'%' : '—'; ?></strong><span>Top Score</span></div>
        <div class="kpi-card"><strong style="color:#ef4444;"><?php echo $lowScore !== null ? $lowScore.'%' : '—'; ?></strong><span>Lowest</span></div>
        <div class="kpi-card"><strong style="color:#166534;"><?php echo $passing; ?></strong><span>Passing</span></div>
        <div class="kpi-card"><strong style="color:#991b1b;"><?php echo $failing; ?></strong><span>Failing</span></div>
      </div>

      <!-- Weights -->
      <div class="weights-bar">
        <i class="fa fa-sliders" style="color:#10b981;font-size:15px;"></i>
        <strong style="font-size:12px;color:#0f172a;">Grade Weights:</strong>
        <label>Quiz <input type="number" id="wWritten" value="<?php echo $weights['written_pct']; ?>" min="0" max="100">%</label>
        <label>Performance Task <input type="number" id="wPerformance" value="<?php echo $weights['performance_pct']; ?>" min="0" max="100">%</label>
        <label>Exam <input type="number" id="wExam" value="<?php echo $weights['exam_pct']; ?>" min="0" max="100">%</label>
        <label><i class="fa fa-smile-o" style="color:#1d4ed8;"></i> Deportment <input type="number" id="wAttendance" value="<?php echo $weights['attendance_pct'] ?? 10; ?>" min="0" max="100">%</label>
        <span id="weightTotal" style="font-size:12px;font-weight:700;color:#10b981;">= <?php
          echo ($weights['written_pct']+$weights['performance_pct']+$weights['exam_pct']+($weights['attendance_pct']??10));
        ?>%</span>

        <button type="button" class="btn-ghost-sm" onclick="openFormulaModal()" style="border: 1.5px solid #10b981; color: #10b981; background: transparent; padding: 5px 10px; font-size: 11px; display: inline-flex; align-items: center; gap: 4px; border-radius: 8px; font-weight: 600; cursor: pointer; margin-left: auto; font-family: 'Inter', sans-serif;">
          <i class="fa fa-calculator"></i> Configure Grading Formula
        </button>
      </div>

      <!-- Consolidated Spreadsheet Table Section -->
      <?php if(empty($studentRows)): ?>
      <div class="empty-state"><i class="fa fa-users"></i><p>No students enrolled yet.</p></div>
      <?php else: ?>
      <div class="tab-toolbar" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
        <div class="tab-toolbar-left" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <span style="font-size:12px;color:#64748b;font-weight:600;"><i class="fa fa-table" style="color:#10b981;"></i> Consolidated Grade Book</span>
        </div>
        <button class="btn-export" onclick="exportCSV('tableUnifiedRecord','class_record')"><i class="fa fa-download"></i> Export CSV</button>
      </div>

      <div class="cr-table-wrap" style="margin-bottom: 24px;">
        <div class="cr-table-scroll">
          <table class="cr-table" id="tableUnifiedRecord">
            <thead>
              <tr>
                <th rowspan="2" class="col-no">#</th>
                <th rowspan="2" class="col-name" style="text-align:left;">Student Name</th>
                <th colspan="<?php echo count($termAttendanceCols) + 1; ?>" style="background:#eff6ff;color:#1d4ed8;border-bottom:2px solid #3b82f6;font-weight:700;"><i class="fa fa-calendar-check-o"></i> Attendance Log</th>
                <th colspan="<?php echo count($termDeportmentCols) + 1; ?>" style="background:#f0f9ff;color:#0369a1;border-bottom:2px solid #0284c7;font-weight:700;">
                  <div style="display:inline-flex;align-items:center;justify-content:center;gap:6px;">
                    <span><i class="fa fa-smile-o"></i> DEPORTMENT (<?php echo $weights['attendance_pct'] ?? 10; ?>%)</span>
                    <button type="button" class="btn-ghost-sm" style="border: 1.5px dashed #0284c7; color: #0284c7; background: #ffffff; width: 22px; height: 22px; padding: 0; font-size: 11px; border-radius: 50%; cursor: pointer; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 1px 3px rgba(0,0,0,0.06); transition: all .15s;" title="Add Deportment Column" onclick="quickAddDeportment(this)">
                      <i class="fa fa-plus"></i>
                    </button>
                  </div>
                </th>
                <th colspan="<?php echo count($termQuizCols) + 1; ?>" style="background:#f0fdf4;color:#15803d;border-bottom:2px solid #10b981;font-weight:700;">
                  <div style="display:inline-flex;align-items:center;justify-content:center;gap:6px;">
                    <span>QUIZ (<?php echo $weights['written_pct']; ?>%)</span>
                    <button type="button" class="btn-ghost-sm" style="border: 1.5px dashed #10b981; color: #10b981; background: #ffffff; width: 22px; height: 22px; padding: 0; font-size: 11px; border-radius: 50%; cursor: pointer; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 1px 3px rgba(0,0,0,0.06); transition: all .15s;" title="Add Quiz Column" onclick="quickAddColumn('written', this)">
                      <i class="fa fa-plus"></i>
                    </button>
                  </div>
                </th>
                <th colspan="<?php echo count($termAssignCols) + 1; ?>" style="background:#fffbeb;color:#b45309;border-bottom:2px solid #f59e0b;font-weight:700;">
                  <div style="display:inline-flex;align-items:center;justify-content:center;gap:6px;">
                    <span>PERFORMANCE TASK (<?php echo $weights['performance_pct']; ?>%)</span>
                    <button type="button" class="btn-ghost-sm" style="border: 1.5px dashed #f59e0b; color: #f59e0b; background: #ffffff; width: 22px; height: 22px; padding: 0; font-size: 11px; border-radius: 50%; cursor: pointer; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 1px 3px rgba(0,0,0,0.06); transition: all .15s;" title="Add Performance Task Column" onclick="quickAddColumn('performance', this)">
                      <i class="fa fa-plus"></i>
                    </button>
                  </div>
                </th>
                <th colspan="<?php echo count($termExamCols) + 1; ?>" style="background:#faf5ff;color:#6b21a8;border-bottom:2px solid #7c3aed;font-weight:700;">
                  <div style="display:inline-flex;align-items:center;justify-content:center;gap:6px;">
                    <span>EXAM (<?php echo $weights['exam_pct']; ?>%)</span>
                    <button type="button" class="btn-ghost-sm" style="border: 1.5px dashed #7c3aed; color: #7c3aed; background: #ffffff; width: 22px; height: 22px; padding: 0; font-size: 11px; border-radius: 50%; cursor: pointer; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 1px 3px rgba(0,0,0,0.06); transition: all .15s;" title="Add Exam Column" onclick="quickAddColumn('exam', this)">
                      <i class="fa fa-plus"></i>
                    </button>
                  </div>
                </th>
                <th colspan="4" style="background:#fef3c7;color:#92400e;border-bottom:2px solid #fbbf24;font-weight:700;">Final Grades</th>
              </tr>
              <tr>
                <!-- Attendance Header Columns -->
                <?php foreach($termAttendanceCols as $col): 
                   $displayTitle = trim($col['title'] ?? '');
                   $sessionDateStr = '';
                   if (!empty($col['session_id'])) {
                       $rawDate = !empty($col['session_started']) ? $col['session_started'] : (!empty($col['session_scheduled']) ? $col['session_scheduled'] : null);
                       if ($rawDate) {
                           $sessionDateStr = date('M d', strtotime($rawDate));
                       } elseif (!empty($displayTitle) && strtotime($displayTitle)) {
                           $sessionDateStr = date('M d', strtotime($displayTitle));
                       }
                       if (empty($displayTitle) || strtotime($displayTitle)) {
                           $displayTitle = $sessionDateStr ?: date('M d', strtotime($displayTitle ?: 'now'));
                           $subDate = '';
                       } else {
                           $subDate = $sessionDateStr;
                       }
                   } else {
                       if (!empty($displayTitle) && strtotime($displayTitle)) {
                           $displayTitle = date('M d', strtotime($displayTitle));
                       }
                       $subDate = '';
                   }
                ?>
                <th style="min-width:70px;background:#f8fafc;">
                  <span style="font-weight:700;font-size:12px;color:#1e40af;"><?php echo htmlspecialchars(strtoupper($displayTitle)); ?></span>
                  <?php if(!empty($subDate) && strcasecmp(trim($displayTitle), trim($subDate)) !== 0): ?>
                    <span style="font-size:10px;font-weight:600;color:#64748b;display:block;"><?php echo strtoupper($subDate); ?></span>
                  <?php endif; ?>
                </th>
                <?php endforeach; ?>
                <th style="min-width:65px;background:#eff6ff;color:#1d4ed8;font-weight:700;">Att %</th>

                <!-- Deportment Header Columns -->
                <?php foreach($termDeportmentCols as $depIdx => $col): 
                   $colDate = !empty($col['created_at']) ? date('M d', strtotime($col['created_at'])) : '';
                   $rawTitle = trim($col['title'] ?? '');
                   $cleanTitle = preg_replace('/^deportment\s*/i', '', $rawTitle);
                   if ($cleanTitle === '' || strtolower($rawTitle) === 'deportment') {
                       $cleanTitle = ($depIdx + 1);
                   }
                ?>
                <th style="min-width:65px;background:#f0f9ff;">
                  <span style="font-weight:700;font-size:12px;color:#0369a1;"><?php echo htmlspecialchars($cleanTitle); ?></span><br>
                  <div style="display:inline-flex;align-items:center;justify-content:center;margin:2px 0;">
                    <input type="number" min="1" max="1000" 
                      value="<?php echo (int)$col['max_score']; ?>" 
                      style="width:38px;height:18px;font-size:10.5px;font-weight:700;color:#334155;background:#fff;border:1px solid #cbd5e1;border-radius:4px;text-align:center;padding:0;outline:none;" 
                      title="Adjust max score"
                      onchange="updateColMaxScore(<?php echo $col['id']; ?>, this.value)"
                      onclick="this.select()">
                  </div>
                  <?php if($colDate): ?>
                    <span style="font-size:10px;font-weight:600;color:#64748b;display:block;"><?php echo $colDate; ?></span>
                  <?php endif; ?>
                  <button class="btn-del-col" onclick="deleteCol(<?php echo $col['id']; ?>)" title="Delete"><i class="fa fa-times"></i></button>
                </th>
                <?php endforeach; ?>
                <th style="min-width:65px;background:#f0f9ff;color:#0369a1;font-weight:700;">Dep %</th>

                <!-- Quizzes Header Columns -->
                <?php foreach($termQuizCols as $qIdx => $col): 
                   $colDate = '';
                   if(!empty($col['quiz_id']) && !empty($col['quiz_created'])){
                       $colDate = date('M d', strtotime($col['quiz_created']));
                   } elseif(!empty($col['created_at'])){
                       $colDate = date('M d', strtotime($col['created_at']));
                   }
                   $rawTitle = trim($col['title'] ?? '');
                   $cleanTitle = preg_replace('/^quiz\s*/i', '', $rawTitle);
                   if ($cleanTitle === '' || strtolower($rawTitle) === 'quiz') {
                       $cleanTitle = ($qIdx + 1);
                   }
                ?>
                <th style="min-width:65px;background:#f9fbf9;">
                  <span style="font-weight:700;font-size:12px;color:#15803d;"><?php echo htmlspecialchars($cleanTitle); ?></span><br>
                  <div style="display:inline-flex;align-items:center;justify-content:center;margin:2px 0;">
                    <input type="number" min="1" max="1000" 
                      value="<?php echo (int)$col['max_score']; ?>" 
                      style="width:38px;height:18px;font-size:10.5px;font-weight:700;color:#334155;background:#fff;border:1px solid #cbd5e1;border-radius:4px;text-align:center;padding:0;outline:none;" 
                      title="Adjust max score"
                      onchange="updateColMaxScore(<?php echo $col['id']; ?>, this.value)"
                      onclick="this.select()">
                  </div>
                  <?php if($colDate): ?>
                    <span style="font-size:10px;font-weight:600;color:#64748b;display:block;"><?php echo $colDate; ?></span>
                  <?php endif; ?>
                  <button class="btn-del-col" onclick="deleteCol(<?php echo $col['id']; ?>)" title="Delete"><i class="fa fa-times"></i></button>
                </th>
                <?php endforeach; ?>
                <th style="min-width:65px;background:#f0fdf4;color:#15803d;font-weight:700;">Avg %</th>

                <!-- Performance Task Header Columns -->
                <?php foreach($termAssignCols as $aIdx => $col): 
                   $colDate = '';
                   if(!empty($col['assignment_id']) && !empty($col['assign_created'])){
                       $colDate = date('M d', strtotime($col['assign_created']));
                   } elseif(!empty($col['created_at'])){
                       $colDate = date('M d', strtotime($col['created_at']));
                   }
                   $rawTitle = trim($col['title'] ?? '');
                   $cleanTitle = preg_replace('/^(performance\s*task|assignment|pt)\s*/i', '', $rawTitle);
                   if ($cleanTitle === '' || strtolower($rawTitle) === 'performance task' || strtolower($rawTitle) === 'assignment') {
                       $cleanTitle = ($aIdx + 1);
                   }
                ?>
                <th style="min-width:65px;background:#fffdfa;">
                  <span style="font-weight:700;font-size:12px;color:#b45309;"><?php echo htmlspecialchars($cleanTitle); ?></span><br>
                  <div style="display:inline-flex;align-items:center;justify-content:center;margin:2px 0;">
                    <input type="number" min="1" max="1000" 
                      value="<?php echo (int)$col['max_score']; ?>" 
                      style="width:38px;height:18px;font-size:10.5px;font-weight:700;color:#334155;background:#fff;border:1px solid #cbd5e1;border-radius:4px;text-align:center;padding:0;outline:none;" 
                      title="Adjust max score"
                      onchange="updateColMaxScore(<?php echo $col['id']; ?>, this.value)"
                      onclick="this.select()">
                  </div>
                  <?php if($colDate): ?>
                    <span style="font-size:10px;font-weight:600;color:#64748b;display:block;"><?php echo $colDate; ?></span>
                  <?php endif; ?>
                  <button class="btn-del-col" onclick="deleteCol(<?php echo $col['id']; ?>)" title="Delete"><i class="fa fa-times"></i></button>
                </th>
                <?php endforeach; ?>
                <th style="min-width:65px;background:#fffdfa;color:#b45309;font-weight:700;">Avg %</th>

                <!-- Quarterly Exams Header Columns -->
                <?php foreach($termExamCols as $eIdx => $col): 
                   $colDate = !empty($col['created_at']) ? date('M d', strtotime($col['created_at'])) : '';
                   $rawTitle = trim($col['title'] ?? '');
                   $cleanTitle = preg_replace('/^exam\s*/i', '', $rawTitle);
                   if ($cleanTitle === '' || strtolower($rawTitle) === 'exam') {
                       $cleanTitle = ($eIdx + 1);
                   }
                ?>
                <th style="min-width:65px;background:#fdfbfe;">
                  <span style="font-weight:700;font-size:12px;color:#6b21a8;"><?php echo htmlspecialchars($cleanTitle); ?></span><br>
                  <div style="display:inline-flex;align-items:center;justify-content:center;margin:2px 0;">
                    <input type="number" min="1" max="1000" 
                      value="<?php echo (int)$col['max_score']; ?>" 
                      style="width:38px;height:18px;font-size:10.5px;font-weight:700;color:#334155;background:#fff;border:1px solid #cbd5e1;border-radius:4px;text-align:center;padding:0;outline:none;" 
                      title="Adjust max score"
                      onchange="updateColMaxScore(<?php echo $col['id']; ?>, this.value)"
                      onclick="this.select()">
                  </div>
                  <?php if($colDate): ?>
                    <span style="font-size:10px;font-weight:600;color:#64748b;display:block;"><?php echo $colDate; ?></span>
                  <?php endif; ?>
                  <button class="btn-del-col" onclick="deleteCol(<?php echo $col['id']; ?>)" title="Delete"><i class="fa fa-times"></i></button>
                </th>
                <?php endforeach; ?>
                <th style="min-width:65px;background:#fdfbfe;color:#6b21a8;font-weight:700;">Avg %</th>

                <!-- Consolidated Grades Header Columns -->
                <th style="min-width:85px;background:#fef3c7;color:#92400e;font-weight:700;">Term Grade%</th>
                <th style="min-width:90px;background:#fef3c7;color:#92400e;font-weight:700;">Overall Grade%</th>
                <th style="min-width:65px;background:#fef3c7;color:#92400e;font-weight:700;">Trans.</th>
                <th style="min-width:80px;background:#fef3c7;color:#92400e;font-weight:700;">Remarks</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($studentRows as $i => $s):
                $uc = $s['user_code'];
                $g  = $studentGrades[$uc];
                $overallGrade = $studentOverallGrades[$uc];
                [$overallStatus,$overallSClr,$overallSBg] = gradeStatus($overallGrade);
                $initials = strtoupper(substr($s['first_name'],0,1).substr($s['last_name'],0,1));
              ?>
              <tr>
                <td style="color:#94a3b8;font-size:11px;"><?php echo $i+1; ?></td>
                <td class="col-name">
                  <div style="display:flex;align-items:center;gap:7px;">
                    <div style="width:26px;height:26px;border-radius:6px;background:linear-gradient(135deg,#1e293b,#0f172a);display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;color:#fff;flex-shrink:0;"><?php echo $initials; ?></div>
                    <div>
                      <div style="font-size:12px;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($s['last_name'].', '.$s['first_name']); ?></div>
                      <div style="font-size:10px;color:#94a3b8;"><?php echo htmlspecialchars($uc); ?></div>
                    </div>
                  </div>
                </td>

                <!-- Attendance Values -->
                <?php 
                $attTotalEarned = 0;
                $attTotalMax = 0;
                foreach($termAttendanceCols as $col):
                  $sc = $scores[$col['id']][$uc] ?? '';
                  $scVal = ($sc !== '') ? floatval($sc) : null;
                  $maxScore = floatval($col['max_score'] ?: 1.00);
                  if ($scVal !== null) {
                      $attTotalEarned += $scVal;
                      $attTotalMax += $maxScore;
                  }
                  $pct = ($scVal !== null && $maxScore > 0) ? ($scVal / $maxScore) * 100 : null;
                ?>
                <td>
                  <?php if($scVal !== null): ?>
                    <?php if($pct >= 75): ?>
                      <span style="display:inline-block;padding:3px 8px;background:#dcfce7;color:#15803d;border-radius:6px;font-size:11px;font-weight:700;"><i class="fa fa-check"></i> Present</span>
                    <?php elseif($pct >= 40): ?>
                      <span style="display:inline-block;padding:3px 8px;background:#fffbeb;color:#b45309;border-radius:6px;font-size:11px;font-weight:700;"><i class="fa fa-clock-o"></i> Late</span>
                    <?php else: ?>
                      <span style="display:inline-block;padding:3px 8px;background:#fef2f2;color:#b91c1c;border-radius:6px;font-size:11px;font-weight:700;"><i class="fa fa-times"></i> Absent</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span style="color:#cbd5e1;">—</span>
                  <?php endif; ?>
                </td>
                <?php endforeach; ?>
                <td class="grade-cell <?php echo ($attTotalMax > 0 && ($attTotalEarned/$attTotalMax)>=0.75) ? 'grade-pass' : 'grade-fail'; ?>" style="background:#eff6ff;color:#1d4ed8;font-weight:800;">
                  <?php echo $attTotalMax > 0 ? round(($attTotalEarned / $attTotalMax) * 100, 1) . '%' : '—'; ?>
                </td>

                <!-- Deportment Values (Graded Score Input) -->
                <?php foreach($termDeportmentCols as $col):
                  $sc = $scores[$col['id']][$uc] ?? '';
                ?>
                <td>
                  <input type="number" class="score-input" min="0" max="<?php echo $col['max_score']; ?>"
                    value="<?php echo $sc !== '' ? htmlspecialchars($sc) : ''; ?>"
                    placeholder="—"
                    onchange="saveScore(<?php echo $col['id']; ?>,'<?php echo addslashes($uc); ?>',this.value)">
                </td>
                <?php endforeach; ?>
                <td class="grade-cell <?php echo $g['components']['deportment']!==null&&$g['components']['deportment']>=75?'grade-pass':'grade-fail'; ?>" style="background:#f0f9ff;color:#0369a1;font-weight:800;">
                  <?php echo $g['components']['deportment'] !== null ? $g['components']['deportment'] . '%' : '—'; ?>
                </td>

                <!-- Quiz Values -->
                <?php foreach($termQuizCols as $col):
                  $sc = $scores[$col['id']][$uc] ?? '';
                ?>
                <td>
                  <?php if(!empty($col['quiz_id'])): ?>
                    <?php if($sc !== ''): ?>
                      <span style="display:inline-block;padding:3px 7px;background:#ede9fe;color:#5b21b6;border-radius:6px;font-size:12px;font-weight:700;" title="Auto-synced"><?php echo htmlspecialchars($sc); ?></span>
                    <?php else: ?>
                      <span style="color:#cbd5e1;font-size:12px;">—</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <input type="number" class="score-input" min="0" max="<?php echo $col['max_score']; ?>"
                      value="<?php echo $sc !== '' ? htmlspecialchars($sc) : ''; ?>"
                      placeholder="—"
                      onchange="saveScore(<?php echo $col['id']; ?>,'<?php echo addslashes($uc); ?>',this.value)">
                  <?php endif; ?>
                </td>
                <?php endforeach; ?>
                <td class="grade-cell <?php echo $g['components']['written']!==null&&$g['components']['written']>=75?'grade-pass':'grade-fail'; ?>" style="background:#f9fbf9;">
                  <?php echo $g['components']['written'] !== null ? $g['components']['written'].'%' : '—'; ?>
                </td>

                <!-- Assignment Values -->
                <?php foreach($termAssignCols as $col):
                  $sc = $scores[$col['id']][$uc] ?? '';
                ?>
                <td>
                  <?php if(!empty($col['assignment_id'])): ?>
                    <?php if($sc !== ''): ?>
                      <span style="display:inline-block;padding:3px 7px;background:#fef3c7;color:#d97706;border-radius:6px;font-size:12px;font-weight:700;" title="Auto-synced"><?php echo htmlspecialchars($sc); ?></span>
                    <?php else: ?>
                      <span style="color:#cbd5e1;font-size:12px;">—</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <input type="number" class="score-input" min="0" max="<?php echo $col['max_score']; ?>"
                      value="<?php echo $sc !== '' ? htmlspecialchars($sc) : ''; ?>"
                      placeholder="—"
                      onchange="saveScore(<?php echo $col['id']; ?>,'<?php echo addslashes($uc); ?>',this.value)">
                  <?php endif; ?>
                </td>
                <?php endforeach; ?>
                <td class="grade-cell <?php echo $g['components']['performance']!==null&&$g['components']['performance']>=75?'grade-pass':'grade-fail'; ?>" style="background:#fffdfa;">
                  <?php echo $g['components']['performance'] !== null ? $g['components']['performance'].'%' : '—'; ?>
                </td>

                <!-- Quarterly Exam Values -->
                <?php foreach($termExamCols as $col):
                  $sc = $scores[$col['id']][$uc] ?? '';
                ?>
                <td>
                  <input type="number" class="score-input" min="0" max="<?php echo $col['max_score']; ?>"
                    value="<?php echo $sc !== '' ? htmlspecialchars($sc) : ''; ?>"
                    placeholder="—"
                    onchange="saveScore(<?php echo $col['id']; ?>,'<?php echo addslashes($uc); ?>',this.value)">
                </td>
                <?php endforeach; ?>
                <td class="grade-cell <?php echo $g['components']['exam']!==null&&$g['components']['exam']>=75?'grade-pass':'grade-fail'; ?>" style="background:#fdfbfe;">
                  <?php echo $g['components']['exam'] !== null ? $g['components']['exam'].'%' : '—'; ?>
                </td>

                <!-- Final Consolidated Values -->
                <td class="grade-cell" style="color:<?php echo $g['final']!==null&&$g['final']>=75?'#166534':'#991b1b'; ?>;">
                  <?php echo $g['final'] !== null ? $g['final'].'%' : '—'; ?>
                </td>
                <td class="grade-cell" style="background:#fef3c7;color:<?php echo $overallGrade!==null&&$overallGrade>=75?'#92400e':'#7f1d1d'; ?>;">
                  <?php echo $overallGrade !== null ? $overallGrade.'%' : '—'; ?>
                </td>
                <td style="font-weight:700;color:#5b21b6;"><?php echo transmute($overallGrade); ?></td>
                <td>
                  <span style="background:<?php echo $overallSBg; ?>;color:<?php echo $overallSClr; ?>;padding:2px 7px;border-radius:5px;font-size:10px;font-weight:700;">
                    <?php echo $overallStatus; ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /tab-record -->


    <!-- ===== TAB 2: ATTENDANCE ===== -->
    <div class="tab-panel" id="tab-attendance">
      <!-- Section 1: Attendance Table -->
      <div class="cr-section-title">
        <span><i class="fa fa-smile-o" style="color:#3b82f6;"></i> Deportment Record (<?php echo $weights['attendance_pct'] ?? 10; ?>%)</span>
        <button type="button" class="btn-add-col" style="border-color:#3b82f6;color:#3b82f6;" onclick="openModal('addF2FModal')">
          <i class="fa fa-plus"></i> Add F2F Deportment
        </button>
      </div>
      <?php if(empty($termDeportmentCols)): ?>
      <div class="empty-state"><i class="fa fa-smile-o"></i><p>No deportment sessions recorded yet.</p></div>
      <?php else: ?>
      <div class="cr-table-wrap" style="margin-bottom: 24px;">
        <div class="cr-table-scroll">
          <table class="cr-table" id="tableAttendance">
            <thead>
              <tr>
                <th class="col-no">#</th>
                <th class="col-name" style="text-align:left;">Student Name</th>
                <?php foreach($termDeportmentCols as $col): 
                   $rawDate = !empty($col['attendance_date']) ? $col['attendance_date'] : (!empty($col['created_at']) ? $col['created_at'] : null);
                   $displayTitle = trim($col['title'] ?? '');
                   $colDate = '';
                   if ($rawDate) {
                       $colDate = date('M d', strtotime($rawDate));
                   }
                   if (empty($displayTitle) || strtotime($displayTitle)) {
                       $displayTitle = $colDate ?: date('M d', strtotime($displayTitle ?: 'now'));
                       $colDate = '';
                   }
                ?>
                <th style="min-width:90px;">
                  <span style="display:inline-flex;align-items:center;gap:2px;background:#dbeafe;color:#1e4ed8;padding:1px 5px;border-radius:4px;font-size:9px;font-weight:700;">BEHAVIOR</span><br>
                  <span style="font-weight:700;"><?php echo htmlspecialchars(strtoupper($displayTitle)); ?></span>
                  <?php if($colDate && strcasecmp(trim($displayTitle), trim($colDate)) !== 0): ?>
                    <span style="font-size:10px;font-weight:600;color:#64748b;display:block;"><?php echo strtoupper($colDate); ?></span>
                  <?php endif; ?>
                </th>
                <?php endforeach; ?>
                <th style="min-width:55px;">Present</th>
                <th style="min-width:60px;">Avg %</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($studentRows as $i => $s):
                $uc = $s['user_code'];
                $presentCount = 0;
                $totalDeportment = 0;
                foreach($termDeportmentCols as $col) {
                  $sc = $scores[$col['id']][$uc] ?? '';
                  if($sc !== '') {
                      $totalDeportment++;
                      if($sc == 1) $presentCount++;
                  }
                }
                $attPct = $studentGrades[$uc]['components']['attendance'];
                $initials = strtoupper(substr($s['first_name'],0,1).substr($s['last_name'],0,1));
              ?>
              <tr>
                <td style="color:#94a3b8;font-size:11px;"><?php echo $i+1; ?></td>
                <td class="col-name">
                  <div style="display:flex;align-items:center;gap:7px;">
                    <div style="width:26px;height:26px;border-radius:6px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;color:#fff;flex-shrink:0;"><?php echo $initials; ?></div>
                    <div>
                      <div style="font-size:12px;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($s['last_name'].', '.$s['first_name']); ?></div>
                      <div style="font-size:10px;color:#94a3b8;"><?php echo htmlspecialchars($uc); ?></div>
                    </div>
                  </div>
                </td>
                <?php foreach($termDeportmentCols as $col): ?>
                <?php $sc = $scores[$col['id']][$uc] ?? ''; ?>
                <td>
                  <?php if($sc !== '' && $sc == 1): ?>
                    <button class="btn-f2f present" onclick="toggleF2F(<?php echo $col['id']; ?>,'<?php echo addslashes($uc); ?>',0)"><i class="fa fa-check"></i> Present</button>
                  <?php elseif($sc !== '' && $sc == 0): ?>
                    <button class="btn-f2f absent" onclick="toggleF2F(<?php echo $col['id']; ?>,'<?php echo addslashes($uc); ?>',1)"><i class="fa fa-times"></i> Absent</button>
                  <?php else: ?>
                    <button class="btn-f2f unrecorded" onclick="toggleF2F(<?php echo $col['id']; ?>,'<?php echo addslashes($uc); ?>',1)"><i class="fa fa-minus"></i> Unrecorded</button>
                  <?php endif; ?>
                </td>
                <?php endforeach; ?>
                <td style="font-weight:700;color:#0f172a;"><?php echo $presentCount; ?>/<?php echo $totalDeportment; ?></td>
                <td class="<?php echo $attPct >= 75 ? 'att-pct-high' : 'att-pct-low'; ?>">
                  <?php echo $attPct !== null ? $attPct.'%' : '—'; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /tab-attendance -->


    <!-- ===== TAB 3: QUIZ SCORES ===== -->
    <div class="tab-panel" id="tab-quizzes">

      <!-- KPI -->
      <div class="kpi-row kpi-row-4">
        <div class="kpi-card"><strong style="color:#10b981;"><?php echo $totalQuizzes; ?></strong><span>Total Quizzes</span></div>
        <div class="kpi-card"><strong style="color:#1d4ed8;"><?php echo $avgQuizPct !== null ? $avgQuizPct.'%' : '—'; ?></strong><span>Class Avg Score</span></div>
        <div class="kpi-card"><strong style="color:#166534;"><?php
          $quizPassing = 0;
          foreach($studentRows as $s) {
            $pcts = [];
            foreach($quizList as $qz) {
              $sub = $quizScores[$qz['id']][$s['user_code']] ?? null;
              if($sub && $sub['total_points'] > 0) $pcts[] = $sub['score']/$sub['total_points']*100;
            }
            if(count($pcts) && round(array_sum($pcts)/count($pcts),1) >= 75) $quizPassing++;
          }
          echo $quizPassing;
        ?></strong><span>Students ≥75%</span></div>
        <div class="kpi-card"><strong style="color:#991b1b;"><?php
          $quizFailing = 0;
          foreach($studentRows as $s) {
            $pcts = [];
            foreach($quizList as $qz) {
              $sub = $quizScores[$qz['id']][$s['user_code']] ?? null;
              if($sub && $sub['total_points'] > 0) $pcts[] = $sub['score']/$sub['total_points']*100;
            }
            if(count($pcts) && round(array_sum($pcts)/count($pcts),1) < 75) $quizFailing++;
          }
          echo $quizFailing;
        ?></strong><span>Students &lt;75%</span></div>
      </div>

      <div class="tab-toolbar">
        <div class="tab-toolbar-left">
          <span style="font-size:12px;color:#64748b;font-weight:600;"><i class="fa fa-pencil-square-o" style="color:#10b981;"></i> Quiz Performance</span>
          <span style="font-size:11px;color:#94a3b8;">Green ≥75% &bull; Red &lt;75% &bull; Gray = not submitted</span>
        </div>
        <button class="btn-export" onclick="exportCSV('tableQuizzes','quiz_scores')"><i class="fa fa-download"></i> Export CSV</button>
      </div>

      <?php if(empty($quizList)): ?>
      <div class="empty-state"><i class="fa fa-pencil-square-o"></i><p>No quizzes found for this class.</p></div>
      <?php else: ?>
      <div class="cr-table-wrap">
        <div class="cr-table-scroll">
          <table class="cr-table" id="tableQuizzes">
            <thead>
              <tr>
                <th class="col-no">#</th>
                <th class="col-name" style="text-align:left;">Student Name</th>
                <?php foreach($quizList as $qz): ?>
                <th style="min-width:90px;">
                  <?php echo htmlspecialchars($qz['title']); ?><br>
                  <?php if($qz['due_date']): ?>
                  <span style="font-weight:400;color:#94a3b8;font-size:9px;"><?php echo date('M d', strtotime($qz['due_date'])); ?></span>
                  <?php endif; ?>
                </th>
                <?php endforeach; ?>
                <th style="min-width:65px;">Avg %</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($studentRows as $i => $s):
                $uc = $s['user_code'];
                $pcts = [];
                foreach($quizList as $qz) {
                  $sub = $quizScores[$qz['id']][$uc] ?? null;
                  if($sub && $sub['total_points'] > 0) $pcts[] = $sub['score']/$sub['total_points']*100;
                }
                $avgPct = count($pcts) ? round(array_sum($pcts)/count($pcts),1) : null;
                $initials = strtoupper(substr($s['first_name'],0,1).substr($s['last_name'],0,1));
              ?>
              <tr>
                <td style="color:#94a3b8;font-size:11px;"><?php echo $i+1; ?></td>
                <td class="col-name">
                  <div style="display:flex;align-items:center;gap:7px;">
                    <div style="width:26px;height:26px;border-radius:6px;background:linear-gradient(135deg,#10b981,#059669);display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;color:#fff;flex-shrink:0;"><?php echo $initials; ?></div>
                    <div>
                      <div style="font-size:12px;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($s['last_name'].', '.$s['first_name']); ?></div>
                      <div style="font-size:10px;color:#94a3b8;"><?php echo htmlspecialchars($uc); ?></div>
                    </div>
                  </div>
                </td>
                <?php foreach($quizList as $qz):
                  // Bug 3 fix: reset variables each iteration so a student with no
                  // submission doesn't accidentally inherit the previous quiz's values.
                  $sub = null; $pct = null; $cls = null; $display = null;
                  $sub = $quizScores[$qz['id']][$uc] ?? null;
                  if($sub) {
                    $pct = $sub['total_points'] > 0 ? round($sub['score']/$sub['total_points']*100,1) : 0;
                    $cls = $pct >= 75 ? 'score-high' : 'score-low';
                    $display = $sub['score'].'/'.$sub['total_points'];
                  }
                ?>
                <td class="<?php echo $sub ? $cls : 'score-none'; ?>">
                  <?php if($sub): ?>
                    <?php echo $display; ?><br>
                    <span style="font-size:10px;">(<?php echo $pct; ?>%)</span>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <?php endforeach; ?>
                <td class="grade-cell <?php echo $avgPct !== null ? ($avgPct >= 75 ? 'grade-pass' : 'grade-fail') : ''; ?>">
                  <?php echo $avgPct !== null ? $avgPct.'%' : '—'; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /tab-quizzes -->


    <!-- ===== TAB 4: ASSIGNMENT SCORES ===== -->
    <div class="tab-panel" id="tab-assignments">

      <!-- KPI -->
      <div class="kpi-row kpi-row-4">
        <div class="kpi-card"><strong style="color:#10b981;"><?php echo $totalAssignments; ?></strong><span>Total Assignments</span></div>
        <div class="kpi-card"><strong style="color:#1d4ed8;"><?php echo $avgAssignPct !== null ? $avgAssignPct.'%' : '—'; ?></strong><span>Class Avg Score</span></div>
        <div class="kpi-card"><strong style="color:#166534;"><?php
          $assignPassing = 0;
          foreach($studentRows as $s) {
            $pcts = [];
            foreach($assignList as $as) {
              $grade = $assignScores[$as['id']][$s['user_code']] ?? null;
              if($grade !== null && $as['points'] > 0) $pcts[] = $grade/$as['points']*100;
            }
            if(count($pcts) && round(array_sum($pcts)/count($pcts),1) >= 75) $assignPassing++;
          }
          echo $assignPassing;
        ?></strong><span>Students ≥75%</span></div>
        <div class="kpi-card"><strong style="color:#991b1b;"><?php
          $assignFailing = 0;
          foreach($studentRows as $s) {
            $pcts = [];
            foreach($assignList as $as) {
              $grade = $assignScores[$as['id']][$s['user_code']] ?? null;
              if($grade !== null && $as['points'] > 0) $pcts[] = $grade/$as['points']*100;
            }
            if(count($pcts) && round(array_sum($pcts)/count($pcts),1) < 75) $assignFailing++;
          }
          echo $assignFailing;
        ?></strong><span>Students &lt;75%</span></div>
      </div>

      <div class="tab-toolbar">
        <div class="tab-toolbar-left">
          <span style="font-size:12px;color:#64748b;font-weight:600;"><i class="fa fa-file-text-o" style="color:#10b981;"></i> Assignment Performance</span>
          <span style="font-size:11px;color:#94a3b8;">Green ≥75% &bull; Red &lt;75% &bull; Gray = not submitted</span>
        </div>
        <button class="btn-export" onclick="exportCSV('tableAssignments','assignment_scores')"><i class="fa fa-download"></i> Export CSV</button>
      </div>

      <?php if(empty($assignList)): ?>
      <div class="empty-state"><i class="fa fa-file-text-o"></i><p>No assignments found for this class.</p></div>
      <?php else: ?>
      <div class="cr-table-wrap">
        <div class="cr-table-scroll">
          <table class="cr-table" id="tableAssignments">
            <thead>
              <tr>
                <th class="col-no">#</th>
                <th class="col-name" style="text-align:left;">Student Name</th>
                <?php foreach($assignList as $as): ?>
                <th style="min-width:100px;">
                  <?php echo htmlspecialchars($as['title']); ?><br>
                  <span style="font-weight:400;color:#94a3b8;font-size:9px;">
                    /<?php echo (int)$as['points']; ?> pts
                    <?php if($as['due_date']): ?>&bull; <?php echo date('M d', strtotime($as['due_date'])); ?><?php endif; ?>
                  </span>
                </th>
                <?php endforeach; ?>
                <th style="min-width:65px;">Avg %</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($studentRows as $i => $s):
                $uc = $s['user_code'];
                $pcts = [];
                foreach($assignList as $as) {
                  $grade = $assignScores[$as['id']][$uc] ?? null;
                  if($grade !== null && $as['points'] > 0) $pcts[] = $grade/$as['points']*100;
                }
                $avgPct = count($pcts) ? round(array_sum($pcts)/count($pcts),1) : null;
                $initials = strtoupper(substr($s['first_name'],0,1).substr($s['last_name'],0,1));
              ?>
              <tr>
                <td style="color:#94a3b8;font-size:11px;"><?php echo $i+1; ?></td>
                <td class="col-name">
                  <div style="display:flex;align-items:center;gap:7px;">
                     <div style="width:26px;height:26px;border-radius:6px;background:linear-gradient(135deg,#10b981,#059669);display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;color:#fff;flex-shrink:0;"><?php echo $initials; ?></div>
                     <div>
                       <div style="font-size:12px;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars($s['last_name'].', '.$s['first_name']); ?></div>
                       <div style="font-size:10px;color:#94a3b8;"><?php echo htmlspecialchars($uc); ?></div>
                     </div>
                  </div>
                </td>
                <?php foreach($assignList as $as):
                  // Bug 3 fix: reset variables each iteration
                  $grade = null; $pct = null; $cls = null;
                  $grade = $assignScores[$as['id']][$uc] ?? null;
                  if($grade !== null) {
                    $pct = $as['points'] > 0 ? round($grade/$as['points']*100,1) : 0;
                    $cls = $pct >= 75 ? 'score-high' : 'score-low';
                  }
                ?>
                <td class="<?php echo $grade !== null ? $cls : 'score-none'; ?>">
                  <?php if($grade !== null): ?>
                    <?php echo $grade; ?>/<?php echo (int)$as['points']; ?><br>
                    <span style="font-size:10px;">(<?php echo $pct; ?>%)</span>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <?php endforeach; ?>
                <td class="grade-cell <?php echo $avgPct !== null ? ($avgPct >= 75 ? 'grade-pass' : 'grade-fail') : ''; ?>">
                  <?php echo $avgPct !== null ? $avgPct.'%' : '—'; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /tab-assignments -->

  </div><!-- /cr-content -->
  <footer class="cl-footer">CenLearn &mdash; Powered by TechnoPal</footer>
</div>



<!-- Grading Formula Modal -->
<div class="cr-modal-overlay" id="gradingFormulaModal">
  <div class="cr-modal" style="max-width: 500px;">
    <div class="cr-modal-head" style="background: linear-gradient(135deg, #10b981, #059669);">
      <h4><i class="fa fa-calculator"></i> Grading Formula & Weights</h4>
      <button class="cr-modal-x" onclick="closeModal('gradingFormulaModal')">&times;</button>
    </div>
    <div class="cr-modal-body" style="max-height: 70vh; overflow-y: auto;">
      
      <!-- Weights Config -->
      <h5 style="font-weight:700; font-size:13px; color:#0f172a; margin-top:0; border-bottom:1px solid #e2e8f0; padding-bottom:6px;">1. Component Weights</h5>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px;">
        <div class="cr-field">
          <label>Quiz Weight (%)</label>
          <input type="number" id="modalWWritten" class="cr-fc" value="<?php echo $weights['written_pct']; ?>" min="0" max="100">
        </div>
        <div class="cr-field">
          <label>Performance Task (%)</label>
          <input type="number" id="modalWPerformance" class="cr-fc" value="<?php echo $weights['performance_pct']; ?>" min="0" max="100">
        </div>
        <div class="cr-field">
          <label>Exam Weight (%)</label>
          <input type="number" id="modalWExam" class="cr-fc" value="<?php echo $weights['exam_pct']; ?>" min="0" max="100">
        </div>
        <div class="cr-field">
          <label>Deportment Weight (%)</label>
          <input type="number" id="modalWAttendance" class="cr-fc" value="<?php echo $weights['attendance_pct'] ?? 10; ?>" min="0" max="100">
        </div>
      </div>



      <!-- Grading Base -->
      <h5 style="font-weight:700; font-size:13px; color:#0f172a; border-bottom:1px solid #e2e8f0; padding-bottom:6px; margin-top:20px;">2. Grading Base (Passing Score Formula)</h5>
      <div class="cr-field" style="margin-bottom:16px;">
        <label>Base Grade Formula</label>
        <select id="modalBaseGradeSelect" class="cr-fc" onchange="toggleModalCustomBase(this.value)">
          <option value="0" <?php echo (int)$weights['base_grade'] === 0 ? 'selected' : ''; ?>>Standard (Base 0) — (raw score / total items) x 100</option>
          <option value="50" <?php echo (int)$weights['base_grade'] === 50 ? 'selected' : ''; ?>>Adjusted Grade (Base 50) — (raw score / total items) x 50 + 50</option>
          <option value="60" <?php echo (int)$weights['base_grade'] === 60 ? 'selected' : ''; ?>>Base 60 — (raw score / total items) x 40 + 60</option>
          <option value="custom" <?php echo !in_array((int)$weights['base_grade'], [0, 50, 60]) ? 'selected' : ''; ?>>Custom Base Grade</option>
        </select>
      </div>
      <div class="cr-field" id="modalCustomBaseWrapper" style="display: <?php echo !in_array((int)$weights['base_grade'], [0, 50, 60]) ? 'block' : 'none'; ?>; margin-bottom:16px;">
        <label>Custom Base Grade value (0 - 99)</label>
        <input type="number" id="modalBaseGradeVal" class="cr-fc" value="<?php echo $weights['base_grade']; ?>" min="0" max="99">
      </div>
      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px; font-size:11px; color:#475569; margin-bottom:16px; line-height:1.4;">
        <i class="fa fa-info-circle" style="color:#3b82f6;"></i> Math Formula: <code id="formulaExplanation">Grade = (raw score / total items) x 100</code>
      </div>

      <!-- Term Weights -->
      <h5 style="font-weight:700; font-size:13px; color:#0f172a; border-bottom:1px solid #e2e8f0; padding-bottom:6px; margin-top:20px;">3. Term Weighting (Overall Grade)</h5>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px;">
        <div class="cr-field">
          <label>Midterm Weight (%)</label>
          <input type="number" id="modalTermMidterm" class="cr-fc" value="<?php echo $weights['midterm_weight'] ?? 40; ?>" min="0" max="100">
        </div>
        <div class="cr-field">
          <label>Final Weight (%)</label>
          <input type="number" id="modalTermFinal" class="cr-fc" value="<?php echo $weights['final_weight'] ?? 60; ?>" min="0" max="100">
        </div>
      </div>
      
      <!-- Total display -->
      <div style="display:flex; justify-content:space-between; align-items:center; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px; font-size:12px; font-weight:700; color:#166534; margin-top:20px;">
        <span>Combined Weights Total:</span>
        <span id="modalWeightsTotalLabel">100%</span>
      </div>
      <div id="modalWeightsAlert" style="display:none; padding:8px; background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:8px; font-size:11px; margin-top:8px; font-weight:600;"></div>

    </div>
    <div class="cr-modal-foot">
      <button class="btn-ghost-sm" onclick="closeModal('gradingFormulaModal')">Cancel</button>
      <button class="btn-green" id="btnSaveModalWeights" onclick="submitModalWeights()"><i class="fa fa-save"></i> Save Configuration</button>
    </div>
  </div>
</div>

<script src="../bower_components/jquery/dist/jquery.min.js"></script>
<script src="../bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script>
var CLASS_ID     = <?php echo $class_id; ?>;
var CURRENT_TERM = '<?php echo $term; ?>';
var QUIZ_COUNT   = <?php echo count($termQuizCols); ?>;
var PERF_COUNT   = <?php echo count($termAssignCols); ?>;
var EXAM_COUNT   = <?php echo count($termExamCols); ?>;
var DEP_COUNT    = <?php echo count($termDeportmentCols); ?>;

// Automatic One-Click Column Inserter (No modal required)
function quickAddColumn(comp, btn){
  if(btn) btn.disabled = true;
  var titles = {
    written: '' + (QUIZ_COUNT + 1),
    performance: '' + (PERF_COUNT + 1),
    exam: '' + (EXAM_COUNT + 1)
  };
  var title = titles[comp] || 'Assessment';
  $.post('class_record_handler.php', {
    action: 'add_column',
    class_id: CLASS_ID,
    component: comp,
    title: title,
    max_score: 100,
    term: CURRENT_TERM
  }, function(r){
    if(r && r.success){
      location.reload();
    } else {
      if(btn) btn.disabled = false;
      alert(r && r.msg ? r.msg : 'Failed to add column');
    }
  }, 'json').fail(function(){
    if(btn) btn.disabled = false;
    alert('Network error adding column');
  });
}

// Inline Adjustable Max Score Inserter
function updateColMaxScore(colId, newMax){
  var val = parseFloat(newMax);
  if(isNaN(val) || val <= 0){
    alert('Please enter a valid max score greater than 0.');
    location.reload();
    return;
  }
  $.post('class_record_handler.php', {
    action: 'update_max_score',
    class_id: CLASS_ID,
    col_id: colId,
    max_score: val
  }, function(r){
    if(r && r.success){
      location.reload();
    } else {
      alert(r && r.msg ? r.msg : 'Failed to update max score');
      location.reload();
    }
  }, 'json').fail(function(){
    alert('Network error updating max score');
    location.reload();
  });
}

// Automatic One-Click Deportment Inserter (Graded Column, No modal required)
function quickAddDeportment(btn){
  if(btn) btn.disabled = true;
  var title = '' + (DEP_COUNT + 1);
  $.post('class_record_handler.php', {
    action: 'add_column',
    class_id: CLASS_ID,
    component: 'deportment',
    title: title,
    max_score: 100,
    term: CURRENT_TERM
  }, function(r){
    if(r && r.success){
      location.reload();
    } else {
      if(btn) btn.disabled = false;
      alert(r && r.msg ? r.msg : 'Failed to add deportment column');
    }
  }, 'json').fail(function(){
    if(btn) btn.disabled = false;
    alert('Network error adding deportment column');
  });
}

// Toggle F2F Attendance
function toggleF2F(colId, studentCode, newScore) {
  $.post('class_record_handler.php', {
    action: 'save_score',
    class_id: CLASS_ID,
    col_id: colId,
    student_code: studentCode,
    score: newScore
  }, function(r) {
    if(r.success) {
      location.reload();
    } else {
      alert(r.msg || 'Failed to save attendance');
    }
  }, 'json');
}

// Add F2F modal submit
function submitAddF2F() {
  var title = document.getElementById('addF2FTitle').value.trim();
  var term  = document.getElementById('addF2FTerm').value;
  var date  = document.getElementById('addF2FDate').value;
  if(!title){ document.getElementById('addF2FAlert').textContent='Title is required.'; document.getElementById('addF2FAlert').style.display='block'; return; }
  document.getElementById('btnSubmitF2F').disabled = true;
  $.post('class_record_handler.php', {
    action: 'add_f2f_column',
    class_id: CLASS_ID,
    title: title,
    term: term,
    date: date
  }, function(r){
    document.getElementById('btnSubmitF2F').disabled = false;
    if(r.success){ closeModal('addF2FModal'); location.reload(); }
    else{ document.getElementById('addF2FAlert').textContent=r.msg||'Failed'; document.getElementById('addF2FAlert').style.display='block'; }
  },'json');
}

// Tab switching
function switchTab(tabId, btn) {
  document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
  document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
  document.getElementById(tabId).classList.add('active');
  btn.classList.add('active');
  // Show/hide topbar add-column buttons only on record tab
  var isRecord = tabId === 'tab-record';
  // Bug 6 fix: querySelector only found first .btn-green; use querySelectorAll for both
  document.querySelectorAll('.cr-topbar-actions .btn-ghost-sm').forEach(function(b){ b.style.display = isRecord ? '' : 'none'; });
  document.querySelectorAll('.cr-topbar-actions .btn-green').forEach(function(b){ b.style.display = isRecord ? '' : 'none'; });
}

// Modal helpers
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.cr-modal-overlay').forEach(function(el){
  el.addEventListener('click', function(e){ if(e.target===el) el.classList.remove('open'); });
});

function openAddCol(comp){
  var labels = {written:'Quiz',performance:'Performance Task',exam:'Exam'};
  document.getElementById('addColComp').value = comp;
  document.getElementById('addColModalTitle').innerHTML = '<i class="fa fa-plus-circle"></i> Add '+labels[comp];
  document.getElementById('addColTitle').value = '';
  document.getElementById('addColMax').value = 100;
  document.getElementById('addColAlert').style.display = 'none';
  openModal('addColModal');
  setTimeout(function(){ document.getElementById('addColTitle').focus(); }, 200);
}

function submitAddCol(){
  var title = document.getElementById('addColTitle').value.trim();
  var max   = document.getElementById('addColMax').value;
  var comp  = document.getElementById('addColComp').value;
  var term  = document.getElementById('addColTerm').value;
  if(!title){ document.getElementById('addColAlert').textContent='Title is required.'; document.getElementById('addColAlert').style.display='block'; return; }
  document.getElementById('btnAddCol').disabled = true;
  $.post('class_record_handler.php',{action:'add_column',class_id:CLASS_ID,component:comp,title:title,max_score:max,term:term},function(r){
    document.getElementById('btnAddCol').disabled = false;
    if(r.success){ closeModal('addColModal'); location.reload(); }
    else{ document.getElementById('addColAlert').textContent=r.msg||'Failed'; document.getElementById('addColAlert').style.display='block'; }
  },'json');
}

function deleteCol(colId){
  if(!confirm('Delete this column and all its scores? This cannot be undone.')) return;
  $.post('class_record_handler.php',{action:'delete_column',class_id:CLASS_ID,col_id:colId},function(r){
    if(r.success) location.reload();
  },'json');
}

// Bug 1 fix: saveScore previously called location.reload() after every save,
// interrupting the teacher mid-typing in another cell.
// Now: only reload when ALL pending saves have completed (no pending timers).
var _saveTimer = {};
function saveScore(colId, stuCode, val){
  var key = colId + '_' + stuCode;
  clearTimeout(_saveTimer[key]);
  _saveTimer[key] = setTimeout(function(){
    delete _saveTimer[key]; // Remove from pending map before AJAX
    $.post('class_record_handler.php',{
      action:'save_score', class_id:CLASS_ID,
      col_id:colId, student_code:stuCode, score:val
    }, function(r){
      if(r.success){
        // Only reload when there are no other saves still queued
        if(Object.keys(_saveTimer).length === 0){
          location.reload();
        }
      }
    }, 'json');
  }, 1000); // 1s debounce — slightly longer than before to let teacher move between cells
}

function updateWeightTotal(){
  var w = Math.min(100, Math.max(0, parseInt(document.getElementById('wWritten').value)||0));
  var p = Math.min(100, Math.max(0, parseInt(document.getElementById('wPerformance').value)||0));
  var e = Math.min(100, Math.max(0, parseInt(document.getElementById('wExam').value)||0));
  var a = Math.min(100, Math.max(0, parseInt(document.getElementById('wAttendance').value)||0));
  var total = w+p+e+a;
  var el = document.getElementById('weightTotal');
  el.textContent = '= '+total+'%';
  el.style.color = total===100 ? '#10b981' : '#ef4444';
  var saveBtn = document.getElementById('btnSaveWeights');
  if(saveBtn) saveBtn.disabled = (total !== 100);
}

['wWritten','wPerformance','wExam','wAttendance'].forEach(function(id){
  var el = document.getElementById(id);
  el.addEventListener('input', function(){
    if(this.value > 100) this.value = 100;
    if(this.value < 0)   this.value = 0;
    updateWeightTotal();
  });
});

function saveWeights(){
  var w = document.getElementById('wWritten').value;
  var p = document.getElementById('wPerformance').value;
  var e = document.getElementById('wExam').value;
  var a = document.getElementById('wAttendance').value;
  if(parseInt(w)+parseInt(p)+parseInt(e)+parseInt(a) !== 100){
    alert('Weights must total exactly 100%.'); return;
  }
  $.post('class_record_handler.php',{
    action:'save_weights', class_id:CLASS_ID,
    written_pct:w, performance_pct:p, exam_pct:e, attendance_pct:a,
    grading_method: '<?php echo $weights['grading_method']; ?>',
    base_grade: '<?php echo $weights['base_grade']; ?>',
    midterm_weight: '<?php echo $weights['midterm_weight']; ?>',
    final_weight: '<?php echo $weights['final_weight']; ?>',
    extra_weights: '[]'
  }, function(r){
    if(r.success) location.reload();
    else alert(r.msg||'Failed');
  },'json');
}

// Grading Formula Modal JS Configuration
function openFormulaModal() {
  openModal('gradingFormulaModal');
  updateModalWeightsTotal();
  updateFormulaExplanation();
}

function escapeHtml(text) {
  return text
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
}

function toggleModalCustomBase(val) {
  var el = document.getElementById('modalCustomBaseWrapper');
  el.style.display = val === 'custom' ? 'block' : 'none';
  updateFormulaExplanation();
}

function updateFormulaExplanation() {
  var selectVal = document.getElementById('modalBaseGradeSelect').value;
  var customVal = parseInt(document.getElementById('modalBaseGradeVal').value) || 0;
  var base = selectVal === 'custom' ? customVal : parseInt(selectVal);
  var formulaLabel = document.getElementById('formulaExplanation');
  if (base === 0) {
    formulaLabel.textContent = "Grade = (raw score / total items) x 100";
  } else if (base === 50) {
    formulaLabel.textContent = "Grade = (raw score / total items) x 50 + 50";
  } else {
    var scale = 100 - base;
    formulaLabel.textContent = "Grade = (raw score / total items) x " + scale + " + " + base;
  }
}

// Bind custom base inputs if present on load
var baseInput = document.getElementById('modalBaseGradeVal');
if(baseInput) baseInput.addEventListener('input', updateFormulaExplanation);

function updateModalWeightsTotal() {
  var w = Math.min(100, Math.max(0, parseInt(document.getElementById('modalWWritten').value)||0));
  var p = Math.min(100, Math.max(0, parseInt(document.getElementById('modalWPerformance').value)||0));
  var e = Math.min(100, Math.max(0, parseInt(document.getElementById('modalWExam').value)||0));
  var a = Math.min(100, Math.max(0, parseInt(document.getElementById('modalWAttendance').value)||0));
  var total = w+p+e+a;
  var label = document.getElementById('modalWeightsTotalLabel');
  if(label) {
    label.textContent = total + '%';
    var alertDiv = document.getElementById('modalWeightsAlert');
    if (total === 100) {
      label.style.color = '#166534';
      if(alertDiv) alertDiv.style.display = 'none';
      document.getElementById('btnSaveModalWeights').disabled = false;
    } else {
      label.style.color = '#ef4444';
      if(alertDiv) {
        alertDiv.textContent = 'Warning: Combined weights sum to ' + total + '%. They must total exactly 100%.';
        alertDiv.style.display = 'block';
      }
      document.getElementById('btnSaveModalWeights').disabled = true;
    }
  }
}

['modalWWritten','modalWPerformance','modalWExam','modalWAttendance'].forEach(function(id){
  var el = document.getElementById(id);
  if(el) el.addEventListener('input', updateModalWeightsTotal);
});

function submitModalWeights() {
  var w = document.getElementById('modalWWritten').value;
  var p = document.getElementById('modalWPerformance').value;
  var e = document.getElementById('modalWExam').value;
  var a = document.getElementById('modalWAttendance').value;
  
  var method = 'sum_of_points';
  var baseSelect = document.getElementById('modalBaseGradeSelect').value;
  var baseVal = baseSelect === 'custom' ? parseInt(document.getElementById('modalBaseGradeVal').value)||0 : parseInt(baseSelect);
  
  var mid = parseInt(document.getElementById('modalTermMidterm').value)||0;
  var fin = parseInt(document.getElementById('modalTermFinal').value)||0;
  
  if(parseInt(w)+parseInt(p)+parseInt(e)+parseInt(a) !== 100){
    alert('Combined weights must total exactly 100%.'); return;
  }
  if(mid + fin !== 100) {
    alert('Midterm and Final term weights must total exactly 100% (e.g. 40% / 60%).'); return;
  }
  
  document.getElementById('btnSaveModalWeights').disabled = true;
  $.post('class_record_handler.php', {
    action: 'save_weights',
    class_id: CLASS_ID,
    written_pct: w,
    performance_pct: p,
    exam_pct: e,
    attendance_pct: a,
    grading_method: method,
    base_grade: baseVal,
    midterm_weight: mid,
    final_weight: fin,
    extra_weights: '[]'
  }, function(r){
    document.getElementById('btnSaveModalWeights').disabled = false;
    if(r.success) {
      location.reload();
    } else {
      alert(r.msg || 'Failed to save configuration');
    }
  }, 'json');
}

// Bug 10 fix: exportCSV used col.innerText which returns empty for <input> elements.
// Now reads input.value when available so score cells export correctly.
function exportCSV(tableId, filename) {
  var table = document.getElementById(tableId);
  if(!table) return;
  var rows = table.querySelectorAll('tr');
  var csv = [];
  rows.forEach(function(row) {
    var cols = row.querySelectorAll('th, td');
    var rowData = [];
    cols.forEach(function(col) {
      var inp = col.querySelector('input.score-input');
      var text = inp
        ? (inp.value !== '' ? inp.value : '')
        : col.innerText.replace(/\n/g,' ').trim();
      text = text.replace(/,/g,';');
      rowData.push('"' + text + '"');
    });
    csv.push(rowData.join(','));
  });
  var blob = new Blob([csv.join('\n')], {type:'text/csv'});
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename + '_<?php echo $class_id; ?>.csv';
  a.click();
}

var ACTIVE_TERM = '<?php echo $term; ?>';

function publishTermGrades(){
  if(!confirm('This will send all calculated ' + (ACTIVE_TERM === 'midterm' ? 'Midterm' : 'Final') + ' grades to the students in this class. Proceed?')) return;
  $.post('class_record_handler.php', {
    action: 'publish_grades',
    class_id: CLASS_ID,
    term: ACTIVE_TERM
  }, function(r){
    if(r.success) {
      alert('Grades successfully sent to students!');
      location.reload();
    } else {
      alert(r.msg || 'Failed to publish grades.');
    }
  }, 'json');
}

function openSidebar(){ document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); }
function closeSidebar(){ document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); }
</script>
</body>
</html>
