<?php
if(session_status() === PHP_SESSION_NONE) session_start();
include_once __DIR__ . '/../includes/conn.php';
include_once __DIR__ . '/analytics_engine.php';
header('Content-Type: application/json');

if(empty($_SESSION['user'])){ echo json_encode(['success'=>false,'msg'=>'Not logged in']); exit; }
$user = $_SESSION['user'];
$uc     = $conn->real_escape_string($user['user_code']);
$role   = strtoupper($user['role'] ?? $user['user_group'] ?? '');
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Teacher: Get class topic analytics ────────────────────────────────────
if($action === 'get_class_analytics' && in_array($role, ['TEACHER', 'ADMIN', 'SUPERADMIN'])){
    $class_id = intval($_GET['class_id'] ?? 0);
    if(!$class_id){ echo json_encode(['success'=>false,'msg'=>'Class ID required']); exit; }

    // Verify teacher owns the class or is admin
    $chk = $conn->query("SELECT id FROM classes WHERE id=$class_id AND (teacher_code='$uc' OR '$role' IN ('ADMIN','SUPERADMIN'))");
    if($chk->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Unauthorized']); exit; }

    // Get class-level topic difficulty (hardest topics first)
    $topic_difficulty = [];
    $tdq = $conn->query("SELECT topic, avg_score_pct, total_attempts FROM class_topic_difficulty WHERE class_id=$class_id ORDER BY avg_score_pct ASC");
    if($tdq && $tdq->num_rows > 0){
        while($td = $tdq->fetch_assoc()){
            $avgPct = floatval($td['avg_score_pct']);
            $stdRef = function_exists('cenlearn_international_standard_reference') ? cenlearn_international_standard_reference($td['topic'], $avgPct, intval($td['total_attempts'])) : null;
            $topic_difficulty[] = [
                'topic' => $td['topic'],
                'avg_score_pct' => $avgPct,
                'total_attempts' => intval($td['total_attempts']),
                'difficulty_level' => $avgPct < 50 ? 'Hard' : ($avgPct < 75 ? 'Medium' : 'Easy'),
                'standard_ref' => $stdRef
            ];
        }
    } else {
        // Fallback: Compute directly from topic_performance table
        $tdq2 = $conn->query("SELECT topic, 
                                     ROUND(AVG(total_points_earned / NULLIF(total_points_available, 0)) * 100, 1) AS avg_score_pct,
                                     SUM(attempts) AS total_attempts
                              FROM topic_performance 
                              WHERE class_id=$class_id AND total_points_available > 0
                              GROUP BY topic
                              ORDER BY avg_score_pct ASC");
        if($tdq2){
            while($td = $tdq2->fetch_assoc()){
                $avgPct = floatval($td['avg_score_pct']);
                $stdRef = function_exists('cenlearn_international_standard_reference') ? cenlearn_international_standard_reference($td['topic'], $avgPct, intval($td['total_attempts'])) : null;
                $topic_difficulty[] = [
                    'topic' => $td['topic'],
                    'avg_score_pct' => $avgPct,
                    'total_attempts' => intval($td['total_attempts']),
                    'difficulty_level' => $avgPct < 50 ? 'Hard' : ($avgPct < 75 ? 'Medium' : 'Easy'),
                    'standard_ref' => $stdRef
                ];
            }
        }
    }

    // Fetch class modules for predictive recommendations
    $modQ = $conn->query("SELECT id, title, original_name, topic FROM class_modules WHERE class_id=$class_id ORDER BY uploaded_at DESC");
    $classMods = [];
    if($modQ){
        while($m = $modQ->fetch_assoc()){
            $classMods[] = $m;
        }
    }

    // Get student-level weak topics with predictive recommendations
    $student_weak_topics = [];
    $stq = $conn->query("SELECT tp.student_code, u.first_name, u.last_name, tp.topic, 
                                ROUND((1 - (tp.total_points_earned / NULLIF(tp.total_points_available, 0))) * 100, 1) AS weakness_score,
                                ROUND((tp.total_points_earned / NULLIF(tp.total_points_available, 0)) * 100, 1) AS mastery_score,
                                tp.total_points_earned, tp.total_points_available, tp.attempts
                         FROM topic_performance tp
                         JOIN users u ON tp.student_code = u.user_code
                         WHERE tp.class_id = $class_id
                         AND tp.total_points_available > 0
                         ORDER BY weakness_score DESC");
    while($st = $stq->fetch_assoc()){
        $tName = $st['topic'];
        $tLower = strtolower(trim($tName));
        $weakness = floatval($st['weakness_score']);
        $mastery = floatval($st['mastery_score']);
        $stUc = $conn->real_escape_string($st['student_code']);

        // Find relevant modules
        $matchedMods = [];
        foreach($classMods as $cm){
            $cmTopic = strtolower(trim($cm['topic'] ?? ''));
            $cmTitle = strtolower(trim($cm['title'] ?? ''));
            if(($cmTopic && strpos($cmTopic, $tLower) !== false) || strpos($cmTitle, $tLower) !== false || strpos($tLower, $cmTitle) !== false){
                $matchedMods[] = [
                    'id' => $cm['id'],
                    'title' => $cm['title'],
                    'original_name' => $cm['original_name']
                ];
            }
        }

        // Find specific quizzes where student was tested on this topic
        $quizCtx = [];
        $qCtxQ = $conn->query("
            SELECT q.id AS quiz_id, q.title AS quiz_title, qs.score AS earned, qs.total_points AS total,
                   ROUND(qs.score / NULLIF(qs.total_points,0)*100, 1) AS overall_pct
            FROM quiz_submissions qs
            JOIN quizzes q ON qs.quiz_id = q.id
            JOIN (
                SELECT DISTINCT quiz_id, LOWER(TRIM(topic)) AS topic
                FROM quiz_questions
                WHERE topic IS NOT NULL AND topic != ''
            ) qq ON qq.quiz_id = q.id
            WHERE qs.student_code = '$stUc' AND q.class_id = $class_id AND qq.topic = '$tLower'
            ORDER BY qs.submitted_at DESC LIMIT 2
        ");
        if($qCtxQ && $qCtxQ->num_rows > 0){
            while($qc = $qCtxQ->fetch_assoc()){
                $quizCtx[] = [
                    'quiz_id' => intval($qc['quiz_id']),
                    'quiz_title' => $qc['quiz_title'],
                    'earned' => floatval($qc['earned']),
                    'total' => floatval($qc['total']),
                    'overall_pct' => floatval($qc['overall_pct'])
                ];
            }
        }

        $stdRef = function_exists('cenlearn_international_standard_reference')
            ? cenlearn_international_standard_reference($tName, $mastery, intval($st['attempts']))
            : null;

        $recText = '';
        if($mastery < 40){
            $recText = "Critical weakness ($mastery%). Bloom L1-L2 Recall Gap. Recommend urgent review of " . (!empty($matchedMods) ? "Module '{$matchedMods[0]['title']}'" : "topic material") . " and remedial 1-on-1 guidance.";
        } elseif($mastery < 75){
            $recText = "Needs practice ($mastery%). Bloom L2-L3 Application Gap. Recommend assigning follow-up quiz or reviewing " . (!empty($matchedMods) ? "'{$matchedMods[0]['title']}'" : "topic notes") . ".";
        } else {
            $recText = "Proficient ($mastery%). Bloom L5-L6 Mastery Met. Keep up the good performance!";
        }

        $student_weak_topics[] = [
            'student_code' => $st['student_code'],
            'student_name' => trim($st['first_name'] . ' ' . $st['last_name']),
            'topic' => $st['topic'],
            'weakness_score' => $weakness,
            'mastery_score' => $mastery,
            'total_points_earned' => floatval($st['total_points_earned']),
            'total_points_available' => floatval($st['total_points_available']),
            'attempts' => intval($st['attempts']),
            'matched_modules' => $matchedMods,
            'quiz_context' => $quizCtx,
            'standard_ref' => $stdRef,
            'recommendation' => $recText,
            'risk_level' => $mastery < 40 ? 'critical' : ($mastery < 75 ? 'warning' : 'good')
        ];
    }

    echo json_encode([
        'success' => true,
        'topic_difficulty' => $topic_difficulty,
        'student_weak_topics' => $student_weak_topics
    ]);
    exit;
}

// ── Student: Get my topic performance ──────────────────────────────────────
if($action === 'get_my_performance' && $role === 'STUDENT'){
    $class_id = intval($_GET['class_id'] ?? 0);
    if(!$class_id){ echo json_encode(['success'=>false,'msg'=>'Class ID required']); exit; }

    // Verify student is class member
    $mem = $conn->query("SELECT id FROM class_members WHERE class_id=$class_id AND user_code='$uc'");
    if($mem->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Access denied']); exit; }

    $my_topics = [];
    $mtq = $conn->query("SELECT topic, total_points_earned, total_points_available, attempts, last_attempt
                         FROM topic_performance
                         WHERE class_id=$class_id AND student_code='$uc'
                         ORDER BY (total_points_earned / NULLIF(total_points_available, 0)) ASC");
    while($mt = $mtq->fetch_assoc()){
        $pct = $mt['total_points_available'] > 0 ? round(($mt['total_points_earned'] / $mt['total_points_available']) * 100, 2) : 0;
        $my_topics[] = [
            'topic' => $mt['topic'],
            'score_pct' => $pct,
            'total_points_earned' => floatval($mt['total_points_earned']),
            'total_points_available' => floatval($mt['total_points_available']),
            'attempts' => intval($mt['attempts']),
            'last_attempt' => $mt['last_attempt'],
            'strength_level' => $pct >= 80 ? 'Strong' : ($pct >= 60 ? 'Okay' : 'Needs Improvement')
        ];
    }

    echo json_encode(['success' => true, 'my_topics' => $my_topics]);
    exit;
}

echo json_encode(['success'=>false,'msg'=>'Invalid action']);
