<?php
if(session_status() === PHP_SESSION_NONE) session_start();
include_once __DIR__ . '/../includes/conn.php';
header('Content-Type: application/json');

if(empty($_SESSION['user'])){
    if(ob_get_length()) ob_clean();
    echo json_encode(['success'=>false,'msg'=>'Not logged in']);
    exit;
}
$user   = $_SESSION['user'];
$uc     = $conn->real_escape_string($user['user_code']);
$role   = strtoupper($user['user_group']);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Assignment Submissions (teacher) ──────────────────────────────────────
if($action === 'submissions' && $role === 'TEACHER'){
    $assign_id = intval($_GET['assignment_id'] ?? 0);
    // Verify ownership
    $q = $conn->query("SELECT id FROM assignments WHERE id=$assign_id AND teacher_code='$uc'");
    if($q->num_rows === 0){
        if(ob_get_length()) ob_clean();
        echo json_encode(['success'=>false,'msg'=>'Unauthorized']);
        exit;
    }

    $res = $conn->query("
        SELECT s.*, u.first_name, u.last_name, u.user_code AS student_code
        FROM assignment_submissions s
        LEFT JOIN users u ON s.student_code = u.user_code
        WHERE s.assignment_id = $assign_id
        ORDER BY s.submitted_at DESC
    ");
    $subs = [];
    while($r = $res->fetch_assoc()){
        $subs[] = [
            'id'           => $r['id'],
            'student_code' => $r['student_code'],
            'student_name' => $r['first_name'].' '.$r['last_name'],
            'file_name'    => $r['file_name'],
            'original_name'=> $r['original_name'],
            'remarks'      => $r['remarks'],
            'grade'        => $r['grade'],
            'submitted_at' => date('M d, Y g:i A', strtotime($r['submitted_at'])),
        ];
    }
    if(ob_get_length()) ob_clean();
    echo json_encode(['success'=>true,'submissions'=>$subs]);
    exit;
}

// ── Quiz Results ──────────────────────────────────────────────────────────
if($action === 'quiz_results'){
    $quiz_id  = intval($_GET['quiz_id'] ?? $_POST['quiz_id'] ?? 0);
    $class_id = intval($_GET['class_id'] ?? $_POST['class_id'] ?? 0);

    safeAddColumns($conn, 'quiz_submissions', [
        'tab_switches'     => 'int(11) DEFAULT 0',
        'fullscreen_exits' => 'int(11) DEFAULT 0'
    ]);

    $q = $conn->query("SELECT id, title, teacher_code, class_id FROM quizzes WHERE id=$quiz_id");
    if(!$q || $q->num_rows === 0){
        if(ob_get_length()) ob_clean();
        echo json_encode(['success'=>false,'msg'=>'Quiz not found']);
        exit;
    }
    $quiz = $q->fetch_assoc();
    $qTitle = $conn->real_escape_string($quiz['title']);
    $tCode  = $conn->real_escape_string($quiz['teacher_code']);

    // Find all linked quiz IDs sharing title and teacher
    $allQuizIds = [$quiz_id];
    $linkedQ = $conn->query("SELECT id FROM quizzes WHERE title='$qTitle' AND teacher_code='$tCode'");
    if($linkedQ){
        while($lr = $linkedQ->fetch_assoc()) $allQuizIds[] = intval($lr['id']);
    }
    $allQuizIds = array_unique($allQuizIds);
    $quizIdList = implode(',', $allQuizIds);

    // Class filter: apply if specific submissions exist for this class member list
    $classFilter = "";
    if($class_id > 0){
        $ckSub = $conn->query("SELECT 1 FROM quiz_submissions s JOIN class_members cm ON cm.user_code = s.student_code AND cm.class_id = $class_id WHERE s.quiz_id IN ($quizIdList) LIMIT 1");
        if($ckSub && $ckSub->num_rows > 0){
            $classFilter = " AND EXISTS (SELECT 1 FROM class_members cm WHERE cm.user_code = s.student_code AND cm.class_id = $class_id) ";
        }
    }

    $res = $conn->query("
        SELECT s.*, u.first_name, u.last_name, u.user_code AS student_code
        FROM quiz_submissions s
        LEFT JOIN users u ON s.student_code = u.user_code
        WHERE s.quiz_id IN ($quizIdList) $classFilter
        ORDER BY s.score DESC, s.submitted_at DESC
    ");
    $subs = [];
    $seenStudents = [];
    if($res){
        while($r = $res->fetch_assoc()){
            $stCode = $r['student_code'] ?: $r['user_code'];
            if(isset($seenStudents[$stCode])) continue;
            $seenStudents[$stCode] = true;

            $fname = trim($r['first_name'] ?? '');
            $lname = trim($r['last_name'] ?? '');
            $sName = ($fname || $lname) ? trim($fname.' '.$lname) : $stCode;
            $subs[] = [
                'quiz_id'          => intval($r['quiz_id']),
                'student_code'     => $stCode,
                'student_name'     => $sName,
                'score'            => floatval($r['score']),
                'total_points'     => intval($r['total_points']),
                'submitted_at'     => $r['submitted_at'] ? date('M d, Y g:i A', strtotime($r['submitted_at'])) : '—',
                'tab_switches'     => intval($r['tab_switches'] ?? 0),
                'fullscreen_exits' => intval($r['fullscreen_exits'] ?? 0),
            ];
        }
    }
    if(ob_get_length()) ob_clean();
    echo json_encode(['success'=>true,'submissions'=>$subs,'quiz_title'=>$quiz['title']]);
    exit;
}

// ── Student Answers Detail ────────────────────────────────────────────────
if($action === 'student_answers'){
    $quiz_id      = intval($_GET['quiz_id'] ?? $_POST['quiz_id'] ?? 0);
    $student_code = $conn->real_escape_string($_GET['student_code'] ?? $_POST['student_code'] ?? '');

    $q = $conn->query("SELECT id, title, teacher_code FROM quizzes WHERE id=$quiz_id");
    if(!$q || $q->num_rows === 0){
        if(ob_get_length()) ob_clean();
        echo json_encode(['success'=>false,'msg'=>'Quiz not found']);
        exit;
    }
    $quiz = $q->fetch_assoc();
    $qTitle = $conn->real_escape_string($quiz['title']);
    $tCode  = $conn->real_escape_string($quiz['teacher_code']);

    // Find all linked quiz IDs
    $allQuizIds = [$quiz_id];
    $linkedQ = $conn->query("SELECT id FROM quizzes WHERE title='$qTitle' AND teacher_code='$tCode'");
    if($linkedQ){
        while($lr = $linkedQ->fetch_assoc()) $allQuizIds[] = intval($lr['id']);
    }
    $allQuizIds = array_unique($allQuizIds);
    $quizIdList = implode(',', $allQuizIds);

    // Get submission
    $subQ = $conn->query("
        SELECT s.quiz_id, s.answers, s.score, s.total_points, s.tab_switches, s.fullscreen_exits, s.submitted_at,
               u.first_name, u.last_name, u.user_code AS student_code
        FROM quiz_submissions s
        LEFT JOIN users u ON s.student_code = u.user_code
        WHERE s.quiz_id IN ($quizIdList) AND s.student_code='$student_code'
        ORDER BY s.id DESC
        LIMIT 1
    ");
    if(!$subQ || $subQ->num_rows === 0){
        if(ob_get_length()) ob_clean();
        echo json_encode(['success'=>false,'msg'=>'Submission not found']);
        exit;
    }
    $sub = $subQ->fetch_assoc();
    $actualQuizId = intval($sub['quiz_id']);

    $answers = json_decode($sub['answers'] ?? '{}', true);
    if(!is_array($answers)) $answers = [];

    // Get questions with correct answers
    $qs = $conn->query("SELECT id,question_text,question_type,options,correct_answer,points,topic FROM quiz_questions WHERE quiz_id=$actualQuizId OR quiz_id=$quiz_id ORDER BY id");
    $questions = [];
    while($r = $qs->fetch_assoc()){
        $rawOpts    = $r['options'] ?? '';
        $opts       = json_decode($rawOpts, true);
        if(!is_array($opts) && !empty($rawOpts)){
            if(strpos($rawOpts, ',') !== false){
                $opts = array_map('trim', explode(',', $rawOpts));
            } else {
                $opts = [$rawOpts];
            }
        }
        $opts = is_array($opts) ? array_values(array_filter($opts, function($o){ return $o !== ''; })) : [];
        $given      = $answers[$r['id']] ?? '';
        $correct    = $r['correct_answer'] ?? '';
        $type       = strtolower(trim($r['question_type'] ?? 'multiple_choice'));

        // Determine if correct
        $isCorrect = false;
        $earnedPts = 0;
        if($type === 'essay'){
            $isCorrect = null;
        } elseif($type === 'enumeration'){
            $correctItems = array_map('trim', explode(',', strtolower($correct)));
            $givenItems   = array_map('trim', explode(',', strtolower($given)));
            $matched = 0;
            foreach($correctItems as $ci){ if(in_array($ci,$givenItems)) $matched++; }
            $earnedPts = count($correctItems)>0 ? round($r['points']*($matched/count($correctItems)),2) : 0;
            $isCorrect = $earnedPts >= $r['points'];
        } elseif($type === 'modified_true_false'){
            $isCorrect = (strtolower(trim($given)) === strtolower(trim($correct)));
            $earnedPts = $isCorrect ? $r['points'] : 0;
        } else {
            $isCorrect = strtolower(trim($given)) === strtolower(trim($correct));
            $earnedPts = $isCorrect ? $r['points'] : 0;
        }

        $questions[] = [
            'id'            => $r['id'],
            'question_text' => $r['question_text'],
            'topic'         => $r['topic'] ?? 'General',
            'question_type' => $type,
            'options'       => $opts,
            'correct_answer'=> $correct,
            'points'        => $r['points'],
            'given_answer'  => $given,
            'is_correct'    => $isCorrect,
            'earned_points' => $earnedPts,
        ];
    }

    if(ob_get_length()) ob_clean();
    echo json_encode([
        'success'          => true,
        'student_name'     => trim(($sub['first_name'] ?? '').' '.($sub['last_name'] ?? '')),
        'score'            => floatval($sub['score']),
        'total_points'     => intval($sub['total_points']),
        'submitted_at'     => $sub['submitted_at'] ? date('M d, Y g:i A', strtotime($sub['submitted_at'])) : '—',
        'tab_switches'     => intval($sub['tab_switches'] ?? 0),
        'fullscreen_exits' => intval($sub['fullscreen_exits'] ?? 0),
        'questions'        => $questions,
    ]);
    exit;
}

// ── Quizzes for Class (teacher) — used by create_quiz.php dashboard ──────────
if($action === 'quizzes_for_class' && $role === 'TEACHER'){
    $class_id = intval($_GET['class_id'] ?? 0);
    // Verify teacher owns this class
    $own = $conn->query("SELECT id FROM classes WHERE id=$class_id AND teacher_code='$uc'");
    if($own->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Unauthorized']); exit; }

    $res = $conn->query("
        SELECT q.id, q.title, q.time_limit, q.due_date, q.is_active, q.term, q.created_at,
               (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=q.id) AS q_count,
               (SELECT COUNT(*) FROM quiz_submissions WHERE quiz_id=q.id) AS sub_count
        FROM quizzes q
        WHERE q.class_id=$class_id AND q.teacher_code='$uc'
        ORDER BY q.created_at DESC
    ");
    $quizzes = [];
    while($r = $res->fetch_assoc()){
        $quizzes[] = [
            'id'         => $r['id'],
            'title'      => $r['title'],
            'time_limit' => $r['time_limit'],
            'due_date'   => $r['due_date'],
            'is_active'  => intval($r['is_active']),
            'term'       => $r['term'],
            'q_count'    => intval($r['q_count']),
            'sub_count'  => intval($r['sub_count']),
            'created_at' => $r['created_at'] ? date('M d, Y', strtotime($r['created_at'])) : '—',
        ];
    }
    echo json_encode(['success'=>true,'quizzes'=>$quizzes]);
    exit;
}

echo json_encode(['success'=>false,'msg'=>'Invalid action']);
