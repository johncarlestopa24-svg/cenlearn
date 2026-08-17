<?php
session_start();
include '../includes/conn.php';
header('Content-Type: application/json');

if(empty($_SESSION['user'])){ echo json_encode(['success'=>false,'msg'=>'Not logged in']); exit; }
$user = $_SESSION['user'];

$uc     = $conn->real_escape_string($user['user_code']);
$role   = strtoupper($user['user_group']);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Teacher: Create Quiz + Questions ─────────────────────────────────────
if($action === 'create' && $role === 'TEACHER'){
    $class_id    = intval($_POST['class_id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $instructions= trim($_POST['instructions'] ?? '');
    $time_limit  = intval($_POST['time_limit'] ?? 0);
    $start_date  = trim($_POST['start_date'] ?? '');
    $due_date    = trim($_POST['due_date'] ?? '');
    $questions   = json_decode($_POST['questions'] ?? '[]', true);

    if(!$title){ echo json_encode(['success'=>false,'msg'=>'Quiz Title is required']); exit; }
    if(empty($questions))     { echo json_encode(['success'=>false,'msg'=>'Add at least one question']); exit; }

    if($class_id > 0) {
        $chk = $conn->query("SELECT id FROM classes WHERE id=$class_id AND teacher_code='$uc'");
        if($chk->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Unauthorized class selected']); exit; }
    }

    // Get all topics from this class's modules
    $module_topics = [];
    $mt = $conn->query("SELECT DISTINCT topic FROM class_modules WHERE class_id=$class_id AND topic IS NOT NULL AND topic != ''");
    if($mt) while($r = $mt->fetch_assoc()) $module_topics[] = trim(strtolower($r['topic']));

    // Collect all unique topics from quiz questions
    $quiz_topics = [];
    foreach($questions as $qitem){
        $t = trim($qitem['topic'] ?? '');
        if($t) $quiz_topics[trim(strtolower($t))] = $t;
    }

    $t   = $conn->real_escape_string($title);
    $ins = $conn->real_escape_string($instructions);
    $tl  = $time_limit > 0 ? $time_limit : 'NULL';
    $sd  = $start_date ? "'".$conn->real_escape_string($start_date)."'" : 'NULL';
    $dd  = $due_date ? "'".$conn->real_escape_string($due_date)."'" : 'NULL';
    $cidVal = $class_id > 0 ? $class_id : 'NULL';
    $sq  = intval($_POST['shuffle_questions'] ?? 0);
    $sa  = intval($_POST['shuffle_answers']   ?? 0);
    $term = $conn->real_escape_string(trim($_POST['term'] ?? 'midterm'));
    if(!in_array($term, ['midterm','final','none'])) $term = 'midterm';

    $insRes = $conn->query("INSERT INTO quizzes (class_id,teacher_code,title,instructions,time_limit,start_date,due_date,shuffle_questions,shuffle_answers,term,is_active)
                  VALUES ($cidVal,'$uc','$t','$ins',$tl,$sd,$dd,$sq,$sa,'$term',1)");
    if(!$insRes) {
        echo json_encode(['success'=>false, 'msg'=>'Failed to save quiz: '.$conn->error]);
        exit;
    }
    $quiz_id = $conn->insert_id;

    // Auto-create a class_record_columns entry for this quiz (if term is not 'none' and target class selected)
    $quiz_max_score = 0;
    foreach($questions as $qitem){ $quiz_max_score += intval($qitem['points'] ?? 1); }
    if($quiz_max_score <= 0) $quiz_max_score = 100;

    if($term !== 'none' && $class_id > 0){
        $qColTitle = $conn->real_escape_string($title);
        // Check if a class_record_columns row for this quiz already exists
        $colCheck = $conn->query("SELECT id FROM class_record_columns WHERE class_id=$class_id AND quiz_id=$quiz_id LIMIT 1");
        if(!$colCheck || $colCheck->num_rows === 0){
            $conn->query("INSERT INTO class_record_columns (class_id, component, title, max_score, sort_order, term, quiz_id)
                          VALUES ($class_id, 'written', '$qColTitle', $quiz_max_score, 0, '$term', $quiz_id)");
        }
    }

    foreach($questions as $qitem){
        $qt  = $conn->real_escape_string(trim($qitem['question_text'] ?? ''));
        $qtp = $conn->real_escape_string($qitem['question_type'] ?? 'multiple_choice');
        $opt = $conn->real_escape_string(json_encode($qitem['options'] ?? []));
        $ans = $conn->real_escape_string($qitem['correct_answer'] ?? '');
        $pts = intval($qitem['points'] ?? 1);
        $topic = $conn->real_escape_string(trim($qitem['topic'] ?? ''));
        if(!$qt) continue;
        $conn->query("INSERT INTO quiz_questions (quiz_id,question_text,topic,question_type,options,correct_answer,points)
                      VALUES ($quiz_id,'$qt','$topic','$qtp','$opt','$ans',$pts)");
    }

    // Check for violations: quiz topics not in module topics
    foreach($quiz_topics as $t_lower => $t_original){
        if(!in_array($t_lower, $module_topics)){
            // Add violation
            $vt = $conn->real_escape_string('missing_material');
            $vd = $conn->real_escape_string("Quiz '{$title}' contains topic '{$t_original}' but no module with this topic exists in the class.");
            $rt = $conn->real_escape_string($t_original);
            $conn->query("INSERT INTO teacher_violations (teacher_code,class_id,violation_type,description,related_topic,related_quiz_id)
                          VALUES ('$uc',$class_id,'$vt','$vd','$rt',$quiz_id)");
        }
    }

    echo json_encode(['success'=>true,'msg'=>'Quiz created','id'=>$quiz_id]);
    exit;
}

// ── Teacher: Delete Quiz ──────────────────────────────────────────────────
if($action === 'delete' && $role === 'TEACHER'){
    $id = intval($_POST['id'] ?? 0);
    $q  = $conn->query("SELECT id FROM quizzes WHERE id=$id AND teacher_code='$uc'");
    if($q->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Not found']); exit; }
    $conn->query("DELETE FROM quiz_submissions WHERE quiz_id=$id");
    $conn->query("DELETE FROM quiz_questions WHERE quiz_id=$id");
    
    // Clean up class record columns and scores
    $colQ = $conn->query("SELECT id FROM class_record_columns WHERE quiz_id=$id");
    if($colQ && $colQ->num_rows > 0) {
        $colId = intval($colQ->fetch_assoc()['id']);
        $conn->query("DELETE FROM class_record_scores WHERE column_id=$colId");
        $conn->query("DELETE FROM class_record_columns WHERE id=$colId");
    }

    $conn->query("DELETE FROM quizzes WHERE id=$id");
    echo json_encode(['success'=>true]);
    exit;
}

// ── Teacher: Copy / Add Quiz to another class ──────────────────────────────────
if($action === 'copy' && $role === 'TEACHER'){
    $quiz_id         = intval($_POST['quiz_id'] ?? 0);
    $target_class_id = intval($_POST['target_class_id'] ?? 0);
    $start_date      = trim($_POST['start_date'] ?? '');
    $due_date        = trim($_POST['due_date'] ?? '');

    if(!$quiz_id || !$target_class_id){ echo json_encode(['success'=>false,'msg'=>'Invalid request']); exit; }

    // Verify teacher owns the source quiz
    $qz = $conn->query("SELECT * FROM quizzes WHERE id=$quiz_id AND teacher_code='$uc'");
    if(!$qz || $qz->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Quiz not found']); exit; }
    $quiz = $qz->fetch_assoc();

    // Verify teacher owns the target class
    $tc = $conn->query("SELECT id FROM classes WHERE id=$target_class_id AND teacher_code='$uc'");
    if(!$tc || $tc->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Target class not found or unauthorized']); exit; }

    // Copy quiz
    $new_title = !empty($_POST['new_title']) ? trim($_POST['new_title']) : $quiz['title'];
    $t   = $conn->real_escape_string($new_title);
    $ins = $conn->real_escape_string($quiz['instructions'] ?? '');
    $tl  = $quiz['time_limit'] ? intval($quiz['time_limit']) : 'NULL';
    $sq  = intval($quiz['shuffle_questions'] ?? 0);
    $sa  = intval($quiz['shuffle_answers'] ?? 0);
    $term= $conn->real_escape_string($quiz['term'] ?? 'midterm');

    $sd = $start_date !== '' ? "'".$conn->real_escape_string($start_date)."'" : (!empty($quiz['start_date']) ? "'".$conn->real_escape_string($quiz['start_date'])."'" : 'NULL');
    $dd = $due_date !== '' ? "'".$conn->real_escape_string($due_date)."'" : (!empty($quiz['due_date']) ? "'".$conn->real_escape_string($quiz['due_date'])."'" : 'NULL');

    $insRes = $conn->query("INSERT INTO quizzes (class_id,teacher_code,title,instructions,time_limit,start_date,due_date,shuffle_questions,shuffle_answers,term,is_active)
                  VALUES ($target_class_id,'$uc','$t','$ins',$tl,$sd,$dd,$sq,$sa,'$term',1)");
    if(!$insRes) {
        echo json_encode(['success'=>false, 'msg'=>'Failed to copy quiz: '.$conn->error]);
        exit;
    }
    $new_quiz_id = $conn->insert_id;

    // Copy all questions
    $quiz_max_score = 0;
    $qs = $conn->query("SELECT * FROM quiz_questions WHERE quiz_id=$quiz_id ORDER BY id");
    while($q = $qs->fetch_assoc()){
        $qt  = $conn->real_escape_string($q['question_text']);
        $qtp = $conn->real_escape_string($q['question_type']);
        $opt = $conn->real_escape_string($q['options'] ?? '[]');
        $ans = $conn->real_escape_string($q['correct_answer'] ?? '');
        $pts = intval($q['points']);
        $topic = $conn->real_escape_string($q['topic'] ?? '');
        $quiz_max_score += $pts;
        $conn->query("INSERT INTO quiz_questions (quiz_id,question_text,topic,question_type,options,correct_answer,points)
                      VALUES ($new_quiz_id,'$qt','$topic','$qtp','$opt','$ans',$pts)");
    }

    if($quiz_max_score <= 0) $quiz_max_score = 100;

    // Auto-create a class_record_columns entry for the target class
    if($term !== 'none' && $target_class_id > 0){
        $qColTitle = $conn->real_escape_string($new_title);
        $colCheck = $conn->query("SELECT id FROM class_record_columns WHERE class_id=$target_class_id AND quiz_id=$new_quiz_id LIMIT 1");
        if(!$colCheck || $colCheck->num_rows === 0){
            $conn->query("INSERT INTO class_record_columns (class_id, component, title, max_score, sort_order, term, quiz_id)
                          VALUES ($target_class_id, 'written', '$qColTitle', $quiz_max_score, 0, '$term', $new_quiz_id)");
        }
    }

    echo json_encode(['success'=>true,'msg'=>'Quiz added to target class with schedule successfully!','new_id'=>$new_quiz_id]);
    exit;
}

// ── Teacher: Toggle active ────────────────────────────────────────────────
if($action === 'toggle' && $role === 'TEACHER'){
    $rawIds = trim($_POST['id'] ?? '0');
    $val = intval($_POST['is_active'] ?? 0);

    $idArr = array_map('intval', explode(',', $rawIds));
    $cleanIds = implode(',', array_filter($idArr));
    if(!$cleanIds){ echo json_encode(['success'=>false,'msg'=>'Invalid quiz ID']); exit; }

    $conn->query("UPDATE quizzes SET is_active=$val WHERE id IN ($cleanIds) AND teacher_code='$uc'");
    echo json_encode(['success'=>true]);
    exit;
}

// ── Teacher: Delete Quiz ──────────────────────────────────────────────────
if($action === 'delete' && $role === 'TEACHER'){
    $rawIds = trim($_POST['id'] ?? '0');
    $idArr = array_map('intval', explode(',', $rawIds));
    $cleanIds = implode(',', array_filter($idArr));
    if(!$cleanIds){ echo json_encode(['success'=>false,'msg'=>'Invalid quiz ID']); exit; }

    $conn->query("DELETE FROM quiz_questions WHERE quiz_id IN ($cleanIds)");
    $conn->query("DELETE FROM quiz_submissions WHERE quiz_id IN ($cleanIds)");
    $conn->query("DELETE FROM quizzes WHERE id IN ($cleanIds) AND teacher_code='$uc'");
    echo json_encode(['success'=>true]);
    exit;
}

// ── Teacher / Admin: Get Submissions for a Quiz ────────────────────────────
if($action === 'get_submissions'){
    $rawQuizId = trim($_POST['quiz_id'] ?? $_GET['quiz_id'] ?? '0');
    $idArr = array_map('intval', explode(',', $rawQuizId));
    $cleanIds = implode(',', array_filter($idArr));
    if(!$cleanIds) $cleanIds = '0';

    $qz = $conn->query("SELECT q.id, q.title, q.class_id, q.teacher_code FROM quizzes q WHERE q.id IN ($cleanIds) LIMIT 1");
    if(!$qz || $qz->num_rows === 0){
        echo json_encode(['success'=>false, 'msg'=>'Quiz not found']);
        exit;
    }
    $quiz = $qz->fetch_assoc();

    $subsQ = $conn->query("
        SELECT s.*, u.first_name, u.last_name, u.user_code,
               ROUND(COALESCE(s.score / NULLIF(s.total_points,0)*100, 0), 1) AS percentage
        FROM quiz_submissions s
        LEFT JOIN users u ON s.student_code = u.user_code
        WHERE s.quiz_id IN ($cleanIds)
        ORDER BY s.submitted_at DESC
    ");

    $submissions = [];
    $highScore = 0;
    $sumScore = 0;
    $violationCount = 0;

    if($subsQ){
        while($row = $subsQ->fetch_assoc()){
            $row['first_name'] = $row['first_name'] ?: 'Student';
            $row['last_name']  = $row['last_name'] ?: $row['student_code'];
            $row['user_code']  = $row['student_code'];
            $submissions[] = $row;
            if(floatval($row['score']) > $highScore) $highScore = floatval($row['score']);
            $sumScore += floatval($row['percentage']);
            if(intval($row['tab_switches'] ?? 0) > 0 || intval($row['fullscreen_exits'] ?? 0) > 0){
                $violationCount++;
            }
        }
    }

    $count = count($submissions);
    $avgPct = $count > 0 ? round($sumScore / $count, 1) : 0;

    echo json_encode([
        'success' => true,
        'quiz' => $quiz,
        'stats' => [
            'submission_count' => $count,
            'avg_pct'          => $avgPct,
            'high_score'       => $highScore,
            'violation_count'  => $violationCount
        ],
        'submissions' => $submissions
    ]);
    exit;
}

// ── Teacher / Student: Get detailed student answers for a submission ────────
if($action === 'get_student_answers'){
    $quiz_id      = intval($_POST['quiz_id'] ?? $_GET['quiz_id'] ?? 0);
    $student_code = $conn->real_escape_string($_POST['student_code'] ?? $_GET['student_code'] ?? '');

    if(!$quiz_id || !$student_code){
        echo json_encode(['success'=>false, 'msg'=>'Quiz ID and Student Code required']);
        exit;
    }

    $q = $conn->query("SELECT id, title, class_id, teacher_code FROM quizzes WHERE id=$quiz_id");
    if(!$q || $q->num_rows === 0){ echo json_encode(['success'=>false, 'msg'=>'Quiz not found']); exit; }
    $quiz = $q->fetch_assoc();

    // Verify permission: Teacher of quiz or the student themselves
    $isOwnerTeacher = ($role === 'TEACHER' && strcasecmp($quiz['teacher_code'], $uc) === 0) || in_array($role, ['ADMIN', 'SUPERADMIN']);
    $isStudentOwner = ($role === 'STUDENT' && strcasecmp($student_code, $uc) === 0);

    if(!$isOwnerTeacher && !$isStudentOwner){
        echo json_encode(['success'=>false, 'msg'=>'Unauthorized']);
        exit;
    }

    $subQ = $conn->query("
        SELECT s.answers, s.score, s.total_points, s.tab_switches, s.fullscreen_exits, s.submitted_at,
               u.first_name, u.last_name, u.user_code
        FROM quiz_submissions s
        LEFT JOIN users u ON s.student_code = u.user_code
        WHERE s.quiz_id=$quiz_id AND s.student_code='$student_code'
        LIMIT 1
    ");
    if(!$subQ || $subQ->num_rows === 0){
        echo json_encode(['success'=>false, 'msg'=>'No submission found for this student']);
        exit;
    }
    $sub = $subQ->fetch_assoc();
    $answers = json_decode($sub['answers'] ?? '{}', true);
    if(!is_array($answers)) $answers = [];

    $qs = $conn->query("SELECT id, question_text, topic, question_type, options, correct_answer, points FROM quiz_questions WHERE quiz_id=$quiz_id ORDER BY id ASC");
    $questions = [];
    while($r = $qs->fetch_assoc()){
        $rawOpts = $r['options'] ?? '';
        $opts = json_decode($rawOpts, true);
        if(!is_array($opts) && !empty($rawOpts)){
            $opts = (strpos($rawOpts, ',') !== false) ? array_map('trim', explode(',', $rawOpts)) : [$rawOpts];
        }
        $opts = is_array($opts) ? array_values(array_filter($opts, function($o){ return $o !== ''; })) : [];

        $given   = trim((string)($answers[$r['id']] ?? ''));
        $correct = trim((string)($r['correct_answer'] ?? ''));
        $type    = strtolower(trim($r['question_type'] ?? 'multiple_choice'));
        $pts     = intval($r['points'] ?? 1);

        $isCorrect = false;
        $earnedPts = 0;

        if($type === 'essay'){
            $isCorrect = null;
            $earnedPts = $pts;
        } elseif($type === 'enumeration'){
            $correctItems = array_map('trim', explode(',', strtolower($correct)));
            $givenItems   = array_map('trim', explode(',', strtolower($given)));
            $matched = 0;
            foreach($correctItems as $ci){ if(in_array($ci, $givenItems)) $matched++; }
            $earnedPts = count($correctItems) > 0 ? round($pts * ($matched / count($correctItems)), 2) : 0;
            $isCorrect = $earnedPts >= $pts;
        } elseif($type === 'modified_true_false'){
            $isCorrect = (strtolower($given) === strtolower($correct));
            $earnedPts = $isCorrect ? $pts : 0;
        } else {
            $isCorrect = (strtolower($given) === strtolower($correct));
            $earnedPts = $isCorrect ? $pts : 0;
        }

        $questions[] = [
            'id'             => intval($r['id']),
            'question_text'  => $r['question_text'],
            'topic'          => $r['topic'] ?: 'General',
            'question_type'  => $type,
            'options'        => $opts,
            'correct_answer' => $correct,
            'points'         => $pts,
            'given_answer'   => $given,
            'is_correct'     => $isCorrect,
            'earned_points'  => $earnedPts
        ];
    }

    echo json_encode([
        'success'          => true,
        'quiz_title'       => $quiz['title'],
        'student_name'     => trim(($sub['first_name'] ?? '').' '.($sub['last_name'] ?? '')),
        'student_code'     => $sub['user_code'] ?: $student_code,
        'score'            => floatval($sub['score']),
        'total_points'     => intval($sub['total_points']),
        'percentage'       => $sub['total_points'] > 0 ? round(($sub['score'] / $sub['total_points']) * 100, 1) : 0,
        'submitted_at'     => $sub['submitted_at'] ? date('M d, Y g:i A', strtotime($sub['submitted_at'])) : '—',
        'tab_switches'     => intval($sub['tab_switches'] ?? 0),
        'fullscreen_exits' => intval($sub['fullscreen_exits'] ?? 0),
        'questions'        => $questions
    ]);
    exit;
}

// ── Student / Teacher: Get quiz questions ───────────────────────────────────
if($action === 'get_questions'){
    $id = intval($_POST['quiz_id'] ?? 0);
    $qz = $conn->query("SELECT q.*, c.id AS cid FROM quizzes q LEFT JOIN classes c ON q.class_id=c.id WHERE q.id=$id");
    if(!$qz || $qz->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Quiz not available']); exit; }
    $quiz = $qz->fetch_assoc();

    $isTeacherOrAdmin = in_array($role, ['TEACHER','ADMIN','SUPERADMIN']) || strcasecmp($quiz['teacher_code'] ?? '', $uc) === 0;

    if(!$isTeacherOrAdmin){
        if(empty($quiz['is_active'])){ echo json_encode(['success'=>false,'msg'=>'Quiz is currently inactive']); exit; }
        if(!empty($quiz['start_date'])){
            if(strtotime($quiz['start_date']) > time()){
                echo json_encode(['success'=>false,'msg'=>'This quiz is scheduled to start on ' . date('M d, Y g:i A', strtotime($quiz['start_date'])) . '. Please check back then!']);
                exit;
            }
        }
        if(!empty($quiz['due_date'])){
            if(strtotime($quiz['due_date']) < time()){
                echo json_encode(['success'=>false,'msg'=>'This quiz has expired and is no longer available']);
                exit;
            }
        }
        if(!empty($quiz['cid'])){
            $mem = $conn->query("SELECT id FROM class_members WHERE class_id={$quiz['cid']} AND user_code='$uc'");
            if(!$mem || $mem->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Access denied']); exit; }
        }
    }

    // Already submitted?
    if(!$isTeacherOrAdmin){
        $done = $conn->query("SELECT score,total_points FROM quiz_submissions WHERE quiz_id=$id AND student_code='$uc'");
        if($done && $done->num_rows > 0){
            $sub = $done->fetch_assoc();
            echo json_encode(['success'=>true, 'already_submitted'=>true, 'msg'=>'Already submitted', 'score'=>floatval($sub['score']), 'total'=>intval($sub['total_points']), 'quiz'=>['title'=>$quiz['title'],'time_limit'=>$quiz['time_limit'],'instructions'=>$quiz['instructions'],'due_date'=>$quiz['due_date']]]);
            exit;
        }
    }

    // ── Attempt & Continuous Time / Violation Limit Tracking for Student ──
    $remaining_seconds = null;
    $current_tab_switches = 0;
    $saved_answers = new stdClass();

    if(!$isTeacherOrAdmin){
        $attQ = $conn->query("SELECT * FROM quiz_attempts WHERE quiz_id=$id AND student_code='$uc' AND status='in_progress' ORDER BY id DESC LIMIT 1");
        if($attQ && $attQ->num_rows > 0){
            $attempt = $attQ->fetch_assoc();
            $attempt_id = intval($attempt['id']);
            $started_time = strtotime($attempt['started_at']);
            $last_hb = !empty($attempt['last_heartbeat']) ? strtotime($attempt['last_heartbeat']) : $started_time;
            $now = time();

            // Disconnection gap in seconds since last active heartbeat/ping
            $disconnect_gap = $now - $last_hb;
            $total_paused = intval($attempt['total_paused_seconds'] ?? 0);

            // If disconnected for more than 15 seconds (connection drop / brownout / browser close):
            if($disconnect_gap > 15){
                // 15-minute max grace pause window = 900 seconds
                $max_grace_pause = 900;
                if($disconnect_gap <= $max_grace_pause){
                    // Disconnected for <= 15 minutes: entire disconnection duration is PAUSED (saved)
                    $total_paused += $disconnect_gap;
                } else {
                    // Disconnected for > 15 minutes: max 15 minutes (900s) are paused.
                    // Any offline time beyond 15 minutes continues running against quiz time limit.
                    $total_paused += $max_grace_pause;
                }
            }

            // Reconnecting/reloading page (brownout/internet loss) counts as +1 violation attempt towards 3 max limit
            $current_tab_switches = intval($attempt['tab_switches'] ?? 0) + 1;

            // Calculate active elapsed seconds (total wall time minus accumulated pause grace seconds)
            $total_wall_seconds = $now - $started_time;
            $active_elapsed_seconds = max(0, $total_wall_seconds - $total_paused);
            $time_limit_mins = intval($quiz['time_limit'] ?? 0);

            // Check if 3 violations reached
            if($current_tab_switches >= 3){
                $conn->query("UPDATE quiz_attempts SET tab_switches=$current_tab_switches, status='terminated' WHERE id=$attempt_id");
                $saved_ans_arr = json_decode($attempt['answers'] ?? '{}', true) ?: [];
                $auto_submit_score = 0; $auto_submit_total = 0;
                $qs_sc = $conn->query("SELECT id,question_type,correct_answer,points FROM quiz_questions WHERE quiz_id=$id");
                while($qrow = $qs_sc->fetch_assoc()){
                    $auto_submit_total += $qrow['points'];
                    $g_ans = strtolower(trim($saved_ans_arr[$qrow['id']] ?? ''));
                    $c_ans = strtolower(trim($qrow['correct_answer'] ?? ''));
                    if($g_ans !== '' && $g_ans === $c_ans){
                        $auto_submit_score += $qrow['points'];
                    }
                }
                $ans_json = $conn->real_escape_string(json_encode($saved_ans_arr));
                $conn->query("INSERT INTO quiz_submissions (quiz_id,student_code,answers,score,total_points,submitted_at,tab_switches,fullscreen_exits)
                              VALUES ($id,'$uc','$ans_json',$auto_submit_score,$auto_submit_total,NOW(),$current_tab_switches,0)
                              ON DUPLICATE KEY UPDATE score=$auto_submit_score, tab_switches=$current_tab_switches");

                echo json_encode([
                    'success' => true,
                    'already_submitted' => true,
                    'max_violations_exceeded' => true,
                    'msg' => 'Maximum 3 violations (tab switches / page reloads) reached! Quiz automatically submitted.',
                    'score' => $auto_submit_score,
                    'total' => $auto_submit_total,
                    'quiz' => ['title'=>$quiz['title'],'time_limit'=>$quiz['time_limit'],'instructions'=>$quiz['instructions']]
                ]);
                exit;
            }

            // Calculate remaining time with 15-min pause grace applied
            if($time_limit_mins > 0){
                $total_limit_secs = $time_limit_mins * 60;
                $remaining_seconds = $total_limit_secs - $active_elapsed_seconds;

                if($remaining_seconds <= 0){
                    $conn->query("UPDATE quiz_attempts SET status='submitted' WHERE id=$attempt_id");
                    $saved_ans_arr = json_decode($attempt['answers'] ?? '{}', true) ?: [];
                    $auto_submit_score = 0; $auto_submit_total = 0;
                    $qs_sc = $conn->query("SELECT id,question_type,correct_answer,points FROM quiz_questions WHERE quiz_id=$id");
                    while($qrow = $qs_sc->fetch_assoc()){
                        $auto_submit_total += $qrow['points'];
                        $g_ans = strtolower(trim($saved_ans_arr[$qrow['id']] ?? ''));
                        $c_ans = strtolower(trim($qrow['correct_answer'] ?? ''));
                        if($g_ans !== '' && $g_ans === $c_ans){
                            $auto_submit_score += $qrow['points'];
                        }
                    }
                    $ans_json = $conn->real_escape_string(json_encode($saved_ans_arr));
                    $conn->query("INSERT INTO quiz_submissions (quiz_id,student_code,answers,score,total_points,submitted_at,tab_switches,fullscreen_exits)
                                  VALUES ($id,'$uc','$ans_json',$auto_submit_score,$auto_submit_total,NOW(),$current_tab_switches,0)
                                  ON DUPLICATE KEY UPDATE score=$auto_submit_score");

                    echo json_encode([
                        'success' => true,
                        'already_submitted' => true,
                        'time_expired' => true,
                        'msg' => 'Quiz time expired! Your answers have been submitted.',
                        'score' => $auto_submit_score,
                        'total' => $auto_submit_total,
                        'quiz' => ['title'=>$quiz['title'],'time_limit'=>$quiz['time_limit'],'instructions'=>$quiz['instructions']]
                    ]);
                    exit;
                }
            }

            $conn->query("UPDATE quiz_attempts SET last_heartbeat=NOW(), total_paused_seconds=$total_paused, tab_switches=$current_tab_switches WHERE id=$attempt_id");
            $saved_answers = json_decode($attempt['answers'] ?? '{}') ?: new stdClass();
        } else {
            // First time starting attempt
            $conn->query("INSERT INTO quiz_attempts (quiz_id, student_code, started_at, last_heartbeat, total_paused_seconds, tab_switches, status)
                          VALUES ($id, '$uc', NOW(), NOW(), 0, 0, 'in_progress')");
            $time_limit_mins = intval($quiz['time_limit'] ?? 0);
            $remaining_seconds = $time_limit_mins > 0 ? ($time_limit_mins * 60) : null;
            $current_tab_switches = 0;
            $saved_answers = new stdClass();
        }
    }

    $qs = $conn->query("SELECT id,question_text,question_type,options,points FROM quiz_questions WHERE quiz_id=$id ORDER BY id");
    $questions = [];
    $questionGroups = []; // Key: question_type, Value: array of questions
    while($r = $qs->fetch_assoc()){
        $rawOpts = $r['options'] ?? '';
        $decodedOpts = json_decode($rawOpts, true);
        if(!is_array($decodedOpts) && !empty($rawOpts)){
            if(strpos($rawOpts, ',') !== false){
                $decodedOpts = array_map('trim', explode(',', $rawOpts));
            } else {
                $decodedOpts = [$rawOpts];
            }
        }
        $r['options'] = is_array($decodedOpts) ? array_values(array_filter($decodedOpts, function($o){ return $o !== ''; })) : [];
        $type = strtolower(trim($r['question_type'] ?? 'multiple_choice'));
        if(($type === 'true_false' || $type === 'tf') && empty($r['options'])){
            $r['options'] = ['True', 'False'];
        }
        if(!isset($questionGroups[$type])) $questionGroups[$type] = [];
        $questionGroups[$type][] = $r;
    }

    // Shuffle questions if enabled — by type group!
    if(!empty($quiz['shuffle_questions'])){
        foreach($questionGroups as &$group){
            shuffle($group);
        }
        unset($group);
    }
    
    // Combine groups back into single array, preserving type order
    $questions = [];
    foreach($questionGroups as $group){
        $questions = array_merge($questions, $group);
    }

    // Shuffle MC answer options if enabled
    if(!empty($quiz['shuffle_answers'])){
        foreach($questions as &$q){
            if($q['question_type'] === 'multiple_choice' && !empty($q['options'])){
                shuffle($q['options']);
            }
        }
        unset($q);
    }

    echo json_encode([
        'success' => true,
        'quiz' => ['title'=>$quiz['title'],'time_limit'=>$quiz['time_limit'],'instructions'=>$quiz['instructions'],'due_date'=>$quiz['due_date']],
        'questions' => $questions,
        'remaining_seconds' => $remaining_seconds,
        'tab_switches' => $current_tab_switches,
        'saved_answers' => $saved_answers
    ]);
    exit;
}

// ── Student: Heartbeat Ping & Save Draft Answers ───────────────────────────
if($action === 'heartbeat' || $action === 'save_draft'){
    $quiz_id = intval($_POST['quiz_id'] ?? 0);
    $answers = isset($_POST['answers']) ? json_decode($_POST['answers'], true) : null;
    if($answers !== null){
        $ans_json = $conn->real_escape_string(json_encode($answers));
        $conn->query("UPDATE quiz_attempts SET answers='$ans_json', last_heartbeat=NOW() WHERE quiz_id=$quiz_id AND student_code='$uc' AND status='in_progress'");
    } else {
        $conn->query("UPDATE quiz_attempts SET last_heartbeat=NOW() WHERE quiz_id=$quiz_id AND student_code='$uc' AND status='in_progress'");
    }
    echo json_encode(['success'=>true]);
    exit;
}

// ── Student: Log Violation (Tab Switch) ───────────────────────────────────
if($action === 'log_violation'){
    $quiz_id = intval($_POST['quiz_id'] ?? 0);
    $conn->query("UPDATE quiz_attempts SET tab_switches = tab_switches + 1 WHERE quiz_id=$quiz_id AND student_code='$uc' AND status='in_progress'");
    $chk = $conn->query("SELECT tab_switches FROM quiz_attempts WHERE quiz_id=$quiz_id AND student_code='$uc' AND status='in_progress' ORDER BY id DESC LIMIT 1");
    $sw = 0;
    if($chk && $chk->num_rows > 0){
        $sw = intval($chk->fetch_assoc()['tab_switches']);
    }
    $limit_reached = ($sw >= 3);
    echo json_encode(['success'=>true, 'tab_switches'=>$sw, 'limit_reached'=>$limit_reached]);
    exit;
}

// ── Student / Teacher: Submit Quiz ──────────────────────────────────────────
if($action === 'submit'){
    $quiz_id = intval($_POST['quiz_id'] ?? 0);
    $answers = json_decode($_POST['answers'] ?? '{}', true);

    $chk = $conn->query("SELECT id FROM quiz_submissions WHERE quiz_id=$quiz_id AND student_code='$uc'");
    if($chk->num_rows > 0){ echo json_encode(['success'=>false,'msg'=>'Already submitted']); exit; }

    // Get quiz details and check start / expiration
    $qz = $conn->query("SELECT q.class_id, q.start_date, q.due_date FROM quizzes q WHERE q.id=$quiz_id");
    if(!$qz || $qz->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Quiz not found']); exit; }
    $quiz = $qz->fetch_assoc();
    $class_id = intval($quiz['class_id'] ?? 0);

    if(!empty($quiz['start_date'])){
        if(strtotime($quiz['start_date']) > time()){
            echo json_encode(['success'=>false,'msg'=>'This quiz has not started yet.']);
            exit;
        }
    }
    if(!empty($quiz['due_date'])){
        if(strtotime($quiz['due_date']) < time()){
            echo json_encode(['success'=>false,'msg'=>'This quiz has expired. Submissions are no longer allowed.']);
            exit;
        }
    }

    // Auto-grade + track per-topic performance
    $qs = $conn->query("SELECT id,question_type,correct_answer,points,topic FROM quiz_questions WHERE quiz_id=$quiz_id");
    $score = 0; $total = 0;
    $topic_scores = []; // key: topic, value: [earned, available]

    while($q = $qs->fetch_assoc()){
        $total += $q['points'];
        $topic = trim($q['topic'] ?? '');
        if(!$topic || strtolower($topic) === 'general') {
            $topic = trim($quiz['title'] ?? 'General');
        }
        $q_earned = 0;
        $given  = strtolower(trim($answers[$q['id']] ?? ''));
        $correct= strtolower(trim($q['correct_answer'] ?? ''));
        $type   = $q['question_type'];

        if($given === '') {
            // No answer, skip but still track available points
        } elseif($type === 'essay' || $type === 'modified_true_false'){
            // Manual grading — skip for now
        } elseif($type === 'enumeration'){
            // Check if all required items are present (comma-separated)
            $correctItems = array_map('trim', explode(',', strtolower($q['correct_answer'])));
            $givenItems   = array_map('trim', explode(',', $given));
            $matched = 0;
            foreach($correctItems as $ci){
                if(in_array($ci, $givenItems)) $matched++;
            }
            // Partial credit: score proportional to matched items
            if(count($correctItems) > 0){
                $q_earned = round($q['points'] * ($matched / count($correctItems)), 2);
                $score += $q_earned;
            }
        } else {
            // MC, T/F, identification — exact match
            if($given === $correct) {
                $q_earned = $q['points'];
                $score += $q_earned;
            }
        }

        // Track per topic
        if($topic) {
            if(!isset($topic_scores[$topic])) $topic_scores[$topic] = ['earned' => 0, 'available' => 0];
            $topic_scores[$topic]['earned'] += $q_earned;
            $topic_scores[$topic]['available'] += $q['points'];
        }
    }

    // Update topic_performance table
    foreach($topic_scores as $topic => $data){
        $t_earned = floatval($data['earned']);
        $t_available = floatval($data['available']);
        $t_topic = $conn->real_escape_string($topic);
        $conn->query("INSERT INTO topic_performance (class_id,student_code,topic,total_points_earned,total_points_available,attempts,last_attempt)
                      VALUES ($class_id,'$uc','$t_topic',$t_earned,$t_available,1,NOW())
                      ON DUPLICATE KEY UPDATE
                        total_points_earned = total_points_earned + $t_earned,
                        total_points_available = total_points_available + $t_available,
                        attempts = attempts + 1,
                        last_attempt = NOW()");
    }

    // Update class_topic_difficulty (refresh average for class)
    foreach($topic_scores as $topic => $data){
        $t_topic = $conn->real_escape_string($topic);
        // Calculate new average for the class
        $conn->query("INSERT INTO class_topic_difficulty (class_id, topic, avg_score_pct, total_attempts)
                      SELECT $class_id, '$t_topic',
                             COALESCE(SUM(total_points_earned) / NULLIF(SUM(total_points_available),0)*100, 0),
                             SUM(attempts)
                      FROM topic_performance
                      WHERE class_id=$class_id AND topic='$t_topic'
                      ON DUPLICATE KEY UPDATE
                        avg_score_pct = VALUES(avg_score_pct),
                        total_attempts = VALUES(total_attempts)");
    }

    $ans_json = $conn->real_escape_string(json_encode($answers));
    $tab_sw   = intval($_POST['tab_switches'] ?? 0);
    $fs_exits = intval($_POST['fullscreen_exits'] ?? 0);
    // Add violation columns if missing
    $conn->query("ALTER TABLE quiz_submissions ADD COLUMN IF NOT EXISTS tab_switches int(11) DEFAULT 0");
    $conn->query("ALTER TABLE quiz_submissions ADD COLUMN IF NOT EXISTS fullscreen_exits int(11) DEFAULT 0");
    $conn->query("INSERT INTO quiz_submissions (quiz_id,student_code,answers,score,total_points,submitted_at,tab_switches,fullscreen_exits)
                  VALUES ($quiz_id,'$uc','$ans_json',$score,$total,NOW(),$tab_sw,$fs_exits)");
    $conn->query("UPDATE quiz_attempts SET status='submitted', tab_switches=$tab_sw WHERE quiz_id=$quiz_id AND student_code='$uc' AND status='in_progress'");

    // ── Auto-sync quiz score into class_record_scores ────────────────────
    // Find the class_record_columns entry linked to this quiz
    $syncQ = $conn->query("SELECT col.id, col.max_score, q.term FROM class_record_columns col
                           JOIN quizzes q ON col.quiz_id = q.id
                           WHERE col.quiz_id = $quiz_id
                             AND col.class_id = $class_id
                           LIMIT 1");
    if($syncQ && $syncQ->num_rows > 0){
        $syncRow = $syncQ->fetch_assoc();
        $col_id   = intval($syncRow['id']);
        $maxScore = floatval($syncRow['max_score']);
        // Scale score to the column's max_score if needed
        $scaledScore = ($total > 0 && $maxScore > 0)
            ? round(($score / $total) * $maxScore, 2)
            : $score;
        $conn->query("INSERT INTO class_record_scores (column_id, class_id, student_code, score)
                      VALUES ($col_id, $class_id, '$uc', $scaledScore)
                      ON DUPLICATE KEY UPDATE score = $scaledScore");
    }
    // ────────────────────────────────────────────────────────────────────

    echo json_encode(['success'=>true,'score'=>$score,'total'=>$total,'msg'=>"Score: $score / $total"]);
    exit;
}

echo json_encode(['success'=>false,'msg'=>'Invalid action']);
