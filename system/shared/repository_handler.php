<?php
if (session_status() === PHP_SESSION_NONE) session_start();
global $conn;
if (!isset($conn) || !$conn) {
    include __DIR__ . '/../includes/conn.php';
}
if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (empty($_SESSION['user'])) {
    echo json_encode(['success' => false, 'msg' => 'Not authenticated']);
    exit;
}

$user = $_SESSION['user'];
$uc   = $conn->real_escape_string($user['user_code']);
$role = strtoupper($user['role'] ?? $user['user_group'] ?? '');

if (!in_array($role, ['TEACHER', 'ADMIN', 'SUPERADMIN'])) {
    echo json_encode(['success' => false, 'msg' => 'Unauthorized access']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── 1. Get all subjects across all teachers with filters ───────────────────────
if ($action === 'get_subjects') {
    $teacherFilter = trim($_GET['teacher_code'] ?? '');
    $programFilter = trim($_GET['program_code'] ?? '');
    $statusFilter  = trim($_GET['status'] ?? 'all'); // 'all', 'active', 'archived'
    $search        = trim($_GET['search'] ?? '');

    $where = ["1=1"];
    $where[] = "(c.is_subject_only = 0 OR c.is_subject_only IS NULL)";

    if ($teacherFilter !== '') {
        $tf = $conn->real_escape_string($teacherFilter);
        $where[] = "c.teacher_code = '$tf'";
    }

    if ($programFilter !== '') {
        $pf = $conn->real_escape_string($programFilter);
        $where[] = "c.program_code = '$pf'";
    }

    if ($statusFilter === 'active') {
        $where[] = "(c.is_archived = 0 OR c.is_archived IS NULL)";
    } elseif ($statusFilter === 'archived') {
        $where[] = "c.is_archived = 1";
    }

    if ($search !== '') {
        $s = $conn->real_escape_string($search);
        $where[] = "(c.subject LIKE '%$s%' OR c.class_name LIKE '%$s%' OR c.class_code LIKE '%$s%' OR u.first_name LIKE '%$s%' OR u.last_name LIKE '%$s%')";
    }

    $whereSql = implode(' AND ', $where);

    $sql = "
        SELECT c.id, c.class_name, c.subject, c.class_code, c.program_code, c.year_level, c.section,
               c.school_year, c.is_archived, c.archived_at, c.created_at, c.teacher_code,
               COALESCE(u.first_name, '') AS teacher_first_name,
               COALESCE(u.last_name, '') AS teacher_last_name,
               COALESCE(u.email_address, '') AS teacher_email,
               COALESCE(qz.quiz_count, 0) AS quiz_count,
               COALESCE(md.module_count, 0) AS module_count,
               COALESCE(asg.assign_count, 0) AS assign_count,
               COALESCE(cm.student_count, 0) AS student_count
        FROM classes c
        LEFT JOIN users u ON c.teacher_code = u.user_code
        LEFT JOIN (
            SELECT class_id, COUNT(*) AS quiz_count 
            FROM quizzes 
            GROUP BY class_id
        ) qz ON qz.class_id = c.id
        LEFT JOIN (
            SELECT class_id, COUNT(*) AS module_count 
            FROM class_modules 
            GROUP BY class_id
        ) md ON md.class_id = c.id
        LEFT JOIN (
            SELECT class_id, COUNT(*) AS assign_count 
            FROM assignments 
            GROUP BY class_id
        ) asg ON asg.class_id = c.id
        LEFT JOIN (
            SELECT class_id, COUNT(DISTINCT user_code) AS student_count 
            FROM class_members 
            GROUP BY class_id
        ) cm ON cm.class_id = c.id
        WHERE $whereSql
        ORDER BY c.is_archived ASC, c.id DESC
    ";

    $res = $conn->query($sql);
    $subjects = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $r['is_own_class'] = (strcasecmp($r['teacher_code'], $uc) === 0);
            $subjects[] = $r;
        }
    }

    // Get unique teachers list for filtering
    $teachersQ = $conn->query("
        SELECT DISTINCT u.user_code, u.first_name, u.last_name 
        FROM classes c 
        JOIN users u ON c.teacher_code = u.user_code 
        WHERE (c.is_subject_only = 0 OR c.is_subject_only IS NULL)
        ORDER BY u.first_name ASC, u.last_name ASC
    ");
    $teachersList = [];
    if ($teachersQ) {
        while ($t = $teachersQ->fetch_assoc()) {
            $teachersList[] = $t;
        }
    }

    // Summary metrics
    $totalFaculty = count($teachersList);
    $totalSubs    = count($subjects);
    $totalQz      = array_sum(array_column($subjects, 'quiz_count'));
    $archivedCnt  = count(array_filter($subjects, fn($s) => !empty($s['is_archived'])));

    echo json_encode([
        'success'      => true,
        'subjects'     => $subjects,
        'teachers'     => $teachersList,
        'stats'        => [
            'total_subjects'  => $totalSubs,
            'total_quizzes'   => $totalQz,
            'total_faculty'   => $totalFaculty,
            'archived_count'  => $archivedCnt
        ]
    ]);
    exit;
}

// ── 2. Get past quizzes and materials for a specific subject ─────────────────
if ($action === 'get_subject_details') {
    $class_id = intval($_GET['class_id'] ?? 0);
    if (!$class_id) {
        echo json_encode(['success' => false, 'msg' => 'Class ID required']);
        exit;
    }

    // Fetch class info
    $cq = $conn->query("
        SELECT c.*, u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
        FROM classes c
        LEFT JOIN users u ON c.teacher_code = u.user_code
        WHERE c.id = $class_id
        LIMIT 1
    ");
    if (!$cq || $cq->num_rows === 0) {
        echo json_encode(['success' => false, 'msg' => 'Subject not found']);
        exit;
    }
    $classInfo = $cq->fetch_assoc();

    // Fetch quizzes for this class
    $quizzesQ = $conn->query("
        SELECT q.id, q.title, q.instructions, q.time_limit, q.due_date, q.term, q.is_active, q.created_at,
               q.teacher_code,
               COALESCE(qq.question_count, 0) AS question_count,
               COALESCE(qq.calculated_points, 0) AS total_points,
               COALESCE(qs.submission_count, 0) AS submission_count
        FROM quizzes q
        LEFT JOIN (
            SELECT quiz_id, COUNT(*) AS question_count, SUM(points) AS calculated_points
            FROM quiz_questions
            GROUP BY quiz_id
        ) qq ON qq.quiz_id = q.id
        LEFT JOIN (
            SELECT quiz_id, COUNT(*) AS submission_count
            FROM quiz_submissions
            GROUP BY quiz_id
        ) qs ON qs.quiz_id = q.id
        WHERE q.class_id = $class_id
        ORDER BY q.id DESC
    ");
    $quizzes = [];
    if ($quizzesQ) {
        while ($qz = $quizzesQ->fetch_assoc()) {
            $quizzes[] = $qz;
        }
    }

    // Fetch learning modules
    $modulesQ = $conn->query("
        SELECT id, title, description, file_name, original_name, file_size, topic, uploaded_at
        FROM class_modules
        WHERE class_id = $class_id
        ORDER BY id DESC
    ");
    $modules = [];
    if ($modulesQ) {
        while ($m = $modulesQ->fetch_assoc()) {
            $modules[] = $m;
        }
    }

    // Fetch assignments
    $assignQ = $conn->query("
        SELECT id, title, instructions, points, due_date, term, created_at
        FROM assignments
        WHERE class_id = $class_id
        ORDER BY id DESC
    ");
    $assignments = [];
    if ($assignQ) {
        while ($a = $assignQ->fetch_assoc()) {
            $assignments[] = $a;
        }
    }

    echo json_encode([
        'success'     => true,
        'class'       => $classInfo,
        'quizzes'     => $quizzes,
        'modules'     => $modules,
        'assignments' => $assignments
    ]);
    exit;
}

// ── 3. Preview full questions of a quiz for teacher review ───────────────────
if ($action === 'preview_quiz') {
    $quiz_id = intval($_GET['quiz_id'] ?? $_POST['quiz_id'] ?? 0);
    if (!$quiz_id) {
        echo json_encode(['success' => false, 'msg' => 'Quiz ID required']);
        exit;
    }

    $qzQ = $conn->query("
        SELECT q.*, c.class_name, c.subject, c.program_code,
               u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
        FROM quizzes q
        LEFT JOIN classes c ON q.class_id = c.id
        LEFT JOIN users u ON q.teacher_code = u.user_code
        WHERE q.id = $quiz_id
        LIMIT 1
    ");
    if (!$qzQ || $qzQ->num_rows === 0) {
        echo json_encode(['success' => false, 'msg' => 'Quiz not found']);
        exit;
    }
    $quiz = $qzQ->fetch_assoc();

    $questionsQ = $conn->query("
        SELECT id, question_text, topic, question_type, options, correct_answer, points
        FROM quiz_questions
        WHERE quiz_id = $quiz_id
        ORDER BY id ASC
    ");
    $questions = [];
    if ($questionsQ) {
        while ($q = $questionsQ->fetch_assoc()) {
            $rawOpts = $q['options'] ?? '';
            $decodedOpts = json_decode($rawOpts, true);
            if (!is_array($decodedOpts) && !empty($rawOpts)) {
                if (strpos($rawOpts, ',') !== false) {
                    $decodedOpts = array_map('trim', explode(',', $rawOpts));
                } else {
                    $decodedOpts = [$rawOpts];
                }
            }
            $q['options'] = is_array($decodedOpts) ? array_values(array_filter($decodedOpts, fn($o) => $o !== '')) : [];
            $type = strtolower(trim($q['question_type'] ?? 'multiple_choice'));
            if (($type === 'true_false' || $type === 'tf') && empty($q['options'])) {
                $q['options'] = ['True', 'False'];
            }
            $questions[] = $q;
        }
    }

    echo json_encode([
        'success'   => true,
        'quiz'      => $quiz,
        'questions' => $questions
    ]);
    exit;
}

// ── 4. Clone a past quiz into logged-in teacher's active class ────────────────
if ($action === 'clone_quiz') {
    $source_quiz_id  = intval($_POST['source_quiz_id'] ?? 0);
    $target_class_id = intval($_POST['target_class_id'] ?? 0);
    $new_title       = trim($_POST['title'] ?? '');

    if (!$source_quiz_id || !$target_class_id) {
        echo json_encode(['success' => false, 'msg' => 'Source quiz and target class are required']);
        exit;
    }

    // Verify logged-in teacher owns target class
    $chkTarget = $conn->query("
        SELECT id, class_name, subject 
        FROM classes 
        WHERE id = $target_class_id AND teacher_code = '$uc' AND (is_archived = 0 OR is_archived IS NULL)
    ");
    if (!$chkTarget || $chkTarget->num_rows === 0) {
        echo json_encode(['success' => false, 'msg' => 'Target class not found or unauthorized']);
        exit;
    }
    $targetClass = $chkTarget->fetch_assoc();

    // Fetch source quiz
    $sqQ = $conn->query("SELECT * FROM quizzes WHERE id = $source_quiz_id LIMIT 1");
    if (!$sqQ || $sqQ->num_rows === 0) {
        echo json_encode(['success' => false, 'msg' => 'Source quiz not found']);
        exit;
    }
    $srcQuiz = $sqQ->fetch_assoc();

    $titleToUse = !empty($new_title) ? $new_title : $srcQuiz['title'] . ' (Imported)';
    $t   = $conn->real_escape_string($titleToUse);
    $ins = $conn->real_escape_string($srcQuiz['instructions'] ?? '');
    $tl  = $srcQuiz['time_limit'] ? intval($srcQuiz['time_limit']) : 'NULL';
    $term = in_array($srcQuiz['term'], ['midterm', 'final', 'none']) ? $srcQuiz['term'] : 'midterm';
    $termSql = $conn->real_escape_string($term);
    $sq  = intval($srcQuiz['shuffle_questions'] ?? 0);
    $sa  = intval($srcQuiz['shuffle_answers'] ?? 0);

    // Insert new cloned quiz
    $insRes = $conn->query("
        INSERT INTO quizzes (class_id, teacher_code, title, instructions, time_limit, shuffle_questions, shuffle_answers, term, is_active)
        VALUES ($target_class_id, '$uc', '$t', '$ins', $tl, $sq, $sa, '$termSql', 1)
    ");
    if (!$insRes) {
        echo json_encode(['success' => false, 'msg' => 'Failed to clone quiz: ' . $conn->error]);
        exit;
    }
    $new_quiz_id = $conn->insert_id;

    // Clone all questions
    $srcQuestions = $conn->query("SELECT * FROM quiz_questions WHERE quiz_id = $source_quiz_id ORDER BY id ASC");
    $clonedQCount = 0;
    $totalPoints  = 0;
    if ($srcQuestions) {
        while ($q = $srcQuestions->fetch_assoc()) {
            $qt  = $conn->real_escape_string($q['question_text']);
            $topic = $conn->real_escape_string($q['topic'] ?? '');
            $qtp = $conn->real_escape_string($q['question_type']);
            $opt = $conn->real_escape_string($q['options'] ?? '[]');
            $ans = $conn->real_escape_string($q['correct_answer'] ?? '');
            $pts = intval($q['points'] ?? 1);
            $totalPoints += $pts;

            $conn->query("
                INSERT INTO quiz_questions (quiz_id, question_text, topic, question_type, options, correct_answer, points)
                VALUES ($new_quiz_id, '$qt', '$topic', '$qtp', '$opt', '$ans', $pts)
            ");
            $clonedQCount++;
        }
    }

    // Auto-create class_record_columns if term is not 'none'
    if ($term !== 'none') {
        $qColTitle = $conn->real_escape_string($titleToUse);
        $maxScore = $totalPoints > 0 ? $totalPoints : 100;
        $conn->query("
            INSERT INTO class_record_columns (class_id, component, title, max_score, sort_order, term, quiz_id)
            VALUES ($target_class_id, 'written', '$qColTitle', $maxScore, 0, '$termSql', $new_quiz_id)
        ");
    }

    echo json_encode([
        'success'     => true,
        'msg'         => "Successfully cloned quiz with $clonedQCount question" . ($clonedQCount !== 1 ? 's' : '') . " to {$targetClass['class_name']}!",
        'new_quiz_id' => $new_quiz_id
    ]);
    exit;
}

// ── 5. Fetch teacher's active classes for the clone target dropdown ──────────
if ($action === 'get_my_active_classes') {
    $myClassesQ = $conn->query("
        SELECT id, class_name, subject, section, program_code
        FROM classes
        WHERE teacher_code = '$uc' AND (is_archived = 0 OR is_archived IS NULL) AND (is_subject_only = 0 OR is_subject_only IS NULL)
        ORDER BY class_name ASC, section ASC
    ");
    $myClasses = [];
    if ($myClassesQ) {
        while ($mc = $myClassesQ->fetch_assoc()) {
            $myClasses[] = $mc;
        }
    }
    echo json_encode(['success' => true, 'classes' => $myClasses]);
    exit;
}

echo json_encode(['success' => false, 'msg' => 'Unknown action']);
