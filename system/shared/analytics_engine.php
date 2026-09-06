<?php
/**
 * CenLearn Predictive Analytics Engine
 * ======================================
 * Hybrid scoring: Rule-based engine + ML model (Python/Flask).
 *
 * Rule-based weights (0–100 risk score):
 *   Quiz performance      30 pts
 *   Assignment grades     25 pts
 *   Missed assignments    20 pts
 *   Live attendance rate  15 pts
 *   Late submissions      10 pts
 *
 * ML model (Random Forest via Python API):
 *   - Trained on the same 5 features
 *   - Returns a predicted risk level + confidence
 *   - If the API is unreachable, falls back to rule-based only
 *
 * Risk Levels:
 *   0–30   = On Track        (green)
 *   31–55  = Needs Attention (yellow)
 *   56–75  = At Risk         (orange)
 *   76–100 = High Risk       (red)
 */

// ── ML API endpoint (configurable via environment or fallback to local port) ──
if (!defined('ML_API_URL')) {
    define('ML_API_URL', getenv('ML_API_URL') ?: 'http://127.0.0.1:5001/predict');
}
if (!defined('ML_API_BATCH_URL')) {
    define('ML_API_BATCH_URL', getenv('ML_API_BATCH_URL') ?: 'http://127.0.0.1:5001/predict_batch');
}
if (!defined('ML_API_TIMEOUT')) {
    define('ML_API_TIMEOUT', 2);   // seconds — fast timeout so UI never hangs
}
if (!defined('ML_TOPIC_URL')) {
    define('ML_TOPIC_URL', getenv('ML_TOPIC_URL') ?: 'http://127.0.0.1:5001/topic_analytics');
}

if (!function_exists('cenlearn_risk_score')):

// ── Internal: call the Python ML API in batch ───────────────────────────
function _ml_predict_batch(array $batch): ?array {
    $payload = json_encode($batch);
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
            'content'       => $payload,
            'timeout'       => ML_API_TIMEOUT,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents(ML_API_BATCH_URL, false, $ctx);
    if ($raw === false) return null;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !empty($decoded['error'])) return null;
    return $decoded;
}

// ── Internal: call the Python ML API ─────────────────────────────────────
function _ml_predict(array $features): ?array {
    $payload = json_encode($features);
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
            'content'       => $payload,
            'timeout'       => ML_API_TIMEOUT,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents(ML_API_URL, false, $ctx);
    if ($raw === false) return null;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !empty($decoded['error'])) return null;
    return $decoded;
}

// ── Map numeric score to risk metadata ───────────────────────────────────
function _risk_meta(int $score): array {
    $score = min(100, max(0, $score));
    if ($score <= 30)     return ['level'=>'on_track',  'label'=>'On Track',        'color'=>'#10b981','bg'=>'#dcfce7','textColor'=>'#166534'];
    if ($score <= 55)     return ['level'=>'attention', 'label'=>'Needs Attention', 'color'=>'#f59e0b','bg'=>'#fef3c7','textColor'=>'#92400e'];
    if ($score <= 75)     return ['level'=>'at_risk',   'label'=>'At Risk',         'color'=>'#f97316','bg'=>'#ffedd5','textColor'=>'#9a3412'];
    return                       ['level'=>'high_risk', 'label'=>'High Risk',       'color'=>'#ef4444','bg'=>'#fee2e2','textColor'=>'#991b1b'];
}

/**
 * Compute risk score for a single student (optionally scoped to one class).
 *
 * @param  mysqli  $conn
 * @param  string  $student_code
 * @param  int|null $class_id
 * @return array
 */
function cenlearn_risk_score($conn, $student_code, $class_id = null): array {
    $uc          = $conn->real_escape_string($student_code);
    $cid         = $class_id ? intval($class_id) : null;
    $classFilter = $cid ? "AND c.id = $cid" : "";
    $classFilterA = $cid ? "AND a.class_id = $cid" : "";

    $totalClassQuizzes = $cid ? intval($conn->query("SELECT COUNT(*) AS c FROM quizzes WHERE class_id = $cid")->fetch_assoc()['c'] ?? 0) : 0;
    $totalClassAssignments = $cid ? intval($conn->query("SELECT COUNT(*) AS c FROM assignments WHERE class_id = $cid")->fetch_assoc()['c'] ?? 0) : 0;

    $rule_score = 0;
    $breakdown  = [];

    // ── 1. Quiz Performance (30 pts) ─────────────────────────────────────
    $qr = $conn->query("
        SELECT AVG(qs.score / NULLIF(qs.total_points,0)) AS avg_pct,
               COUNT(qs.id) AS taken
        FROM quiz_submissions qs
        JOIN quizzes q ON qs.quiz_id = q.id
        JOIN classes c ON q.class_id = c.id
        WHERE qs.student_code = '$uc'
          AND qs.total_points > 0
          $classFilter
    ");
    $qd      = $qr->fetch_assoc();
    $quizTaken = intval($qd['taken'] ?? 0);
    $quizAvg = $quizTaken > 0 ? floatval($qd['avg_pct']) : null;
    if ($quizAvg !== null) {
        $quizPenalty = $quizAvg >= 0.60 ? 0 : round((0.60 - $quizAvg) / 0.60 * 30);
        $rule_score += $quizPenalty;
        $quizPct = round($quizAvg * 100, 1);
        $breakdown['quiz'] = [
            'label'   => 'Quiz Performance',
            'value'   => $quizPct . '%',
            'penalty' => $quizPenalty,
            'max'     => 30,
            'taken'   => $quizTaken,
            'total'   => $totalClassQuizzes ?: $quizTaken,
            'pct'     => $quizPct,
            'detail'  => $totalClassQuizzes > 0 ? "$quizTaken of $totalClassQuizzes quizzes taken (Avg: $quizPct%)" : "$quizTaken quiz" . ($quizTaken > 1 ? 'zes' : '') . " taken (Avg: $quizPct%)",
            'status'  => $quizAvg >= 0.75 ? 'good' : ($quizAvg >= 0.50 ? 'warn' : 'bad'),
        ];
    } else {
        $quizAvg = 0.5;   // neutral for ML
        $breakdown['quiz'] = [
            'label'   => 'Quiz Performance',
            'value'   => 'No data',
            'penalty' => 0,
            'max'     => 30,
            'taken'   => 0,
            'total'   => $totalClassQuizzes,
            'pct'     => 0,
            'detail'  => 'No quizzes taken yet',
            'status'  => 'neutral',
        ];
    }

    // ── 2. Assignment Grades (25 pts) ────────────────────────────────────
    $ar = $conn->query("
        SELECT AVG(s.grade / NULLIF(a.points,0)) AS avg_pct,
               COUNT(s.id) AS graded
        FROM assignment_submissions s
        JOIN assignments a ON s.assignment_id = a.id
        JOIN classes c ON a.class_id = c.id
        WHERE s.student_code = '$uc'
          AND s.grade IS NOT NULL
          AND a.points > 0
          $classFilter
    ");
    $ad        = $ar->fetch_assoc();
    $assignGraded = intval($ad['graded'] ?? 0);
    $assignAvg = $assignGraded > 0 ? floatval($ad['avg_pct']) : null;
    if ($assignAvg !== null) {
        $assignPenalty = $assignAvg >= 0.60 ? 0 : round((0.60 - $assignAvg) / 0.60 * 25);
        $rule_score += $assignPenalty;
        $assignPct = round($assignAvg * 100, 1);
        $breakdown['assignment_grade'] = [
            'label'   => 'Assignment Grades',
            'value'   => $assignPct . '%',
            'penalty' => $assignPenalty,
            'max'     => 25,
            'graded'  => $assignGraded,
            'total'   => $totalClassAssignments ?: $assignGraded,
            'pct'     => $assignPct,
            'detail'  => $totalClassAssignments > 0 ? "$assignGraded of $totalClassAssignments assignments graded (Avg: $assignPct%)" : "$assignGraded graded submissions (Avg: $assignPct%)",
            'status'  => $assignAvg >= 0.75 ? 'good' : ($assignAvg >= 0.50 ? 'warn' : 'bad'),
        ];
    } else {
        $assignAvg = 0.5;
        $breakdown['assignment_grade'] = [
            'label'   => 'Assignment Grades',
            'value'   => 'No data',
            'penalty' => 0,
            'max'     => 25,
            'graded'  => 0,
            'total'   => $totalClassAssignments,
            'pct'     => 0,
            'detail'  => 'No graded assignments yet',
            'status'  => 'neutral',
        ];
    }

    // ── 3. Missed Assignments (20 pts) ───────────────────────────────────
    // Only assignments with past due date count as missed
    $mr = $conn->query("
        SELECT COUNT(*) AS missed
        FROM assignments a
        JOIN classes c ON a.class_id = c.id
        JOIN class_members cm ON cm.class_id = c.id AND cm.user_code = '$uc'
        WHERE NOT EXISTS (
            SELECT 1 FROM assignment_submissions s
            WHERE s.assignment_id = a.id AND s.student_code = '$uc'
        )
        AND a.due_date IS NOT NULL AND a.due_date < NOW()
        $classFilter
    ");
    $missed      = intval($mr->fetch_assoc()['missed'] ?? 0);
    $missPenalty = min($missed * 5, 20);
    $rule_score += $missPenalty;
    $breakdown['missed'] = [
        'label'   => 'Missed Assignments',
        'value'   => $missed === 0 ? '0 missed' : ($missed . ' missed'),
        'penalty' => $missPenalty,
        'max'     => 20,
        'count'   => $missed,
        'pct'     => $missed === 0 ? 100 : max(0, 100 - ($missed * 25)),
        'detail'  => $missed === 0 ? 'All past-due assignments submitted' : ($missed . ' overdue assignment' . ($missed > 1 ? 's' : '') . ' not submitted'),
        'status'  => $missed === 0 ? 'good' : ($missed <= 2 ? 'warn' : 'bad'),
    ];

    // ── 4. Live Attendance Rate (15 pts) ─────────────────────────────────
    $lr = $conn->query("
        SELECT COUNT(DISTINCT ls.id) AS total_sessions,
               COUNT(DISTINCT la.session_id) AS attended
        FROM live_sessions ls
        JOIN classes c ON ls.class_id = c.id
        JOIN class_members cm ON cm.class_id = c.id AND cm.user_code = '$uc'
        LEFT JOIN live_attendance la ON la.session_id = ls.id AND la.student_code = '$uc'
        WHERE ls.status IN ('live','ended')
          $classFilter
    ");
    $ld            = $lr->fetch_assoc();
    $totalSessions = intval($ld['total_sessions'] ?? 0);
    $attended      = intval($ld['attended'] ?? 0);
    $attendRate    = $totalSessions > 0 ? ($attended / $totalSessions) : null;
    if ($attendRate !== null) {
        $attendPenalty = $attendRate >= 0.50 ? 0 : round((0.50 - $attendRate) / 0.50 * 15);
        $rule_score += $attendPenalty;
        $attendPct = round($attendRate * 100);
        $breakdown['attendance'] = [
            'label'    => 'Live Attendance',
            'value'    => $attendPct . '%',
            'penalty'  => $attendPenalty,
            'max'      => 15,
            'attended' => $attended,
            'total'    => $totalSessions,
            'pct'      => $attendPct,
            'detail'   => "$attended of $totalSessions live sessions attended ($attendPct%)",
            'status'   => $attendRate >= 0.75 ? 'good' : ($attendRate >= 0.50 ? 'warn' : 'bad'),
        ];
    } else {
        $attendRate = 0.75;
        $breakdown['attendance'] = [
            'label'    => 'Live Attendance',
            'value'    => 'No sessions',
            'penalty'  => 0,
            'max'      => 15,
            'attended' => 0,
            'total'    => 0,
            'pct'      => 100,
            'detail'   => 'No live class sessions conducted yet',
            'status'   => 'neutral',
        ];
    }

    // ── 5. Late Submissions (10 pts) ─────────────────────────────────────
    $lsr = $conn->query("
        SELECT COUNT(*) AS total_subs,
               SUM(CASE WHEN a.due_date IS NOT NULL AND s.submitted_at > a.due_date THEN 1 ELSE 0 END) AS late_subs
        FROM assignment_submissions s
        JOIN assignments a ON s.assignment_id = a.id
        JOIN classes c ON a.class_id = c.id
        WHERE s.student_code = '$uc'
          $classFilter
    ");
    $lsd       = $lsr->fetch_assoc();
    $totalSubs = intval($lsd['total_subs'] ?? 0);
    $lateSubs  = intval($lsd['late_subs'] ?? 0);
    $lateRate  = $totalSubs > 0 ? ($lateSubs / $totalSubs) : null;
    if ($lateRate !== null) {
        $latePenalty = $lateRate <= 0.50 ? 0 : round(($lateRate - 0.50) / 0.50 * 10);
        $rule_score += $latePenalty;
        $onTimePct = round((1 - $lateRate) * 100);
        $breakdown['late'] = [
            'label'   => 'Late Submissions',
            'value'   => $lateSubs . ' of ' . $totalSubs,
            'penalty' => $latePenalty,
            'max'     => 10,
            'late'    => $lateSubs,
            'total'   => $totalSubs,
            'rate'    => round($lateRate * 100),
            'pct'     => $onTimePct,
            'detail'  => $lateSubs === 0 ? "All $totalSubs submissions turned in on time" : "$lateSubs of $totalSubs submissions were submitted after deadline",
            'status'  => $lateRate <= 0.20 ? 'good' : ($lateRate <= 0.50 ? 'warn' : 'bad'),
        ];
    } else {
        $lateRate = 0.0;
        $breakdown['late'] = [
            'label'   => 'Late Submissions',
            'value'   => 'No data',
            'penalty' => 0,
            'max'     => 10,
            'late'    => 0,
            'total'   => 0,
            'rate'    => 0,
            'pct'     => 100,
            'detail'  => 'No assignment submissions on record',
            'status'  => 'neutral',
        ];
    }

    $rule_score = min(100, max(0, $rule_score));

    // ── Call ML API ───────────────────────────────────────────────────────
    $ml = _ml_predict([
        'quiz_avg_pct'   => $quizAvg,
        'assign_avg_pct' => $assignAvg,
        'missed_count'   => $missed,
        'attend_rate'    => $attendRate,
        'late_rate'      => $lateRate,
    ]);

    // ── Determine final score & level ─────────────────────────────────────
    $ml_active = is_array($ml) && !empty($ml['model_active']);
    if ($ml_active) {
        $final_score = (int) round($rule_score * 0.60 + $ml['ml_score'] * 0.40);
    } else {
        $final_score = $rule_score;
    }

    $academicHealth = max(0, min(100, 100 - $final_score));
    $meta = _risk_meta($final_score);

    // Recommendation logic
    $recommendation = "Student is performing well and on track.";
    if ($missed > 0) {
        $recommendation = "Urgent: Follow up on $missed missed assignment" . ($missed > 1 ? 's' : '') . " to recover up to 20 penalty points.";
    } elseif ($quizAvg !== null && $quizAvg < 0.60) {
        $recommendation = "Low quiz average (" . round($quizAvg * 100) . "%). Recommend reviewing difficult topic modules & practice questions.";
    } elseif ($assignAvg !== null && $assignAvg < 0.60) {
        $recommendation = "Assignment grades require attention (" . round($assignAvg * 100) . "%). Review rubric feedback & submission quality.";
    } elseif ($attendRate !== null && $attendRate < 0.50 && $totalSessions > 0) {
        $recommendation = "Low live session attendance (" . round($attendRate * 100) . "%). Encourage attending scheduled virtual classes.";
    } elseif ($lateRate !== null && $lateRate > 0.40 && $totalSubs > 0) {
        $recommendation = "Frequent late submissions (" . round($lateRate * 100) . "%). Remind student of upcoming task deadlines.";
    }

    return array_merge($meta, [
        'score'           => $final_score,
        'academic_health' => $academicHealth,
        'rule_score'      => $rule_score,
        'recommendation'  => $recommendation,
        'breakdown'       => $breakdown,
        // ML fields (null when API is offline)
        'ml_active'       => $ml_active,
        'ml_level'        => $ml['ml_level']      ?? null,
        'ml_label'        => $ml['ml_label']      ?? null,
        'ml_score'        => $ml['ml_score']      ?? null,
        'ml_confidence'   => $ml['ml_confidence'] ?? null,
        'probabilities'   => $ml['probabilities'] ?? null,
    ]);
}

/**
 * Get risk scores for all students in a class.
 */
function cenlearn_class_analytics($conn, $class_id): array {
    $cid = intval($class_id);

    // Get total class quizzes and assignments
    $totalClassQuizzes = intval($conn->query("SELECT COUNT(*) AS c FROM quizzes WHERE class_id = $cid")->fetch_assoc()['c'] ?? 0);
    $totalClassAssignments = intval($conn->query("SELECT COUNT(*) AS c FROM assignments WHERE class_id = $cid")->fetch_assoc()['c'] ?? 0);

    // Fetch student features in a single SQL pass
    $query = "
        SELECT 
            u.user_code, u.first_name, u.last_name, u.section, u.year_level,
            COALESCE(q.avg_pct, 0.5) AS quiz_avg,
            COALESCE(q.taken, 0) AS quiz_taken,
            COALESCE(a.avg_pct, 0.5) AS assign_avg,
            COALESCE(a.graded, 0) AS assign_graded,
            COALESCE(m.missed, 0) AS missed,
            COALESCE(att.attend_rate, 0.75) AS attend_rate,
            COALESCE(att.attended, 0) AS attend_attended,
            COALESCE(att.total_sessions, 0) AS attend_total,
            COALESCE(lat.late_rate, 0.0) AS late_rate,
            COALESCE(lat.late_subs, 0) AS late_subs,
            COALESCE(lat.total_subs, 0) AS late_total
        FROM class_members cm
        JOIN users u ON cm.user_code = u.user_code
        -- Quizzes
        LEFT JOIN (
            SELECT qs.student_code, 
                   AVG(qs.score / NULLIF(qs.total_points, 0)) AS avg_pct,
                   COUNT(qs.id) AS taken
            FROM quiz_submissions qs
            JOIN quizzes q ON qs.quiz_id = q.id
            WHERE qs.total_points > 0 AND q.class_id = $cid
            GROUP BY qs.student_code
        ) q ON cm.user_code = q.student_code
        -- Assignments
        LEFT JOIN (
            SELECT s.student_code, 
                   AVG(s.grade / NULLIF(a.points, 0)) AS avg_pct,
                   COUNT(s.id) AS graded
            FROM assignment_submissions s
            JOIN assignments a ON s.assignment_id = a.id
            WHERE s.grade IS NOT NULL AND a.points > 0 AND a.class_id = $cid
            GROUP BY s.student_code
        ) a ON cm.user_code = a.student_code
        -- Missed assignments (only past due)
        LEFT JOIN (
            SELECT cm_inner.user_code, COUNT(ass.id) AS missed
            FROM class_members cm_inner
            JOIN assignments ass ON ass.class_id = cm_inner.class_id
            WHERE ass.due_date IS NOT NULL AND ass.due_date < NOW()
              AND ass.class_id = $cid
              AND NOT EXISTS (
                  SELECT 1 FROM assignment_submissions s
                  WHERE s.assignment_id = ass.id AND s.student_code = cm_inner.user_code
              )
            GROUP BY cm_inner.user_code
        ) m ON cm.user_code = m.user_code
        -- Attendance
        LEFT JOIN (
            SELECT cm_inner.user_code,
                   COUNT(DISTINCT la.session_id) AS attended,
                   COUNT(DISTINCT ls.id) AS total_sessions,
                   COUNT(DISTINCT la.session_id) / NULLIF(COUNT(DISTINCT ls.id), 0) AS attend_rate
            FROM class_members cm_inner
            JOIN live_sessions ls ON ls.class_id = cm_inner.class_id AND ls.status IN ('live', 'ended')
            LEFT JOIN live_attendance la ON la.session_id = ls.id AND la.student_code = cm_inner.user_code
            WHERE ls.class_id = $cid
            GROUP BY cm_inner.user_code
        ) att ON cm.user_code = att.user_code
        -- Late submissions
        LEFT JOIN (
            SELECT s.student_code,
                   COUNT(s.id) AS total_subs,
                   SUM(CASE WHEN a.due_date IS NOT NULL AND s.submitted_at > a.due_date THEN 1 ELSE 0 END) AS late_subs,
                   SUM(CASE WHEN a.due_date IS NOT NULL AND s.submitted_at > a.due_date THEN 1 ELSE 0 END) / NULLIF(COUNT(s.id), 0) AS late_rate
            FROM assignment_submissions s
            JOIN assignments a ON s.assignment_id = a.id
            WHERE a.class_id = $cid
            GROUP BY s.student_code
        ) lat ON cm.user_code = lat.student_code
        WHERE cm.class_id = $cid AND u.user_group = 'STUDENT'
        ORDER BY u.last_name, u.first_name
    ";

    $students = $conn->query($query);
    if (!$students) return [];

    $batch_payload = [];
    $student_list = [];

    while ($s = $students->fetch_assoc()) {
        $uc = $s['user_code'];
        $quiz_taken  = intval($s['quiz_taken']);
        $quiz_avg    = $quiz_taken > 0 ? floatval($s['quiz_avg']) : 0.5;
        $assign_grd  = intval($s['assign_graded']);
        $assign_avg  = $assign_grd > 0 ? floatval($s['assign_avg']) : 0.5;
        $missed      = intval($s['missed']);
        $attend_tot  = intval($s['attend_total']);
        $attend_att  = intval($s['attend_attended']);
        $attend_rate = $attend_tot > 0 ? floatval($s['attend_rate']) : 0.75;
        $late_tot    = intval($s['late_total']);
        $late_subs   = intval($s['late_subs']);
        $late_rate   = $late_tot > 0 ? floatval($s['late_rate']) : 0.0;

        // Calculate rule-based score
        $quizPenalty   = $quiz_taken > 0 ? ($quiz_avg >= 0.60 ? 0 : round((0.60 - $quiz_avg) / 0.60 * 30)) : 0;
        $assignPenalty = $assign_grd > 0 ? ($assign_avg >= 0.60 ? 0 : round((0.60 - $assign_avg) / 0.60 * 25)) : 0;
        $missPenalty   = min($missed * 5, 20);
        $attendPenalty = $attend_tot > 0 ? ($attend_rate >= 0.50 ? 0 : round((0.50 - $attend_rate) / 0.50 * 15)) : 0;
        $latePenalty   = $late_tot > 0 ? ($late_rate <= 0.50 ? 0 : round(($late_rate - 0.50) / 0.50 * 10)) : 0;
        $rule_score    = min(100, max(0, $quizPenalty + $assignPenalty + $missPenalty + $attendPenalty + $latePenalty));

        $quizPct = $quiz_taken > 0 ? round($quiz_avg * 100, 1) : 0;
        $assignPct = $assign_grd > 0 ? round($assign_avg * 100, 1) : 0;
        $attendPct = $attend_tot > 0 ? round($attend_rate * 100) : 100;
        $onTimePct = $late_tot > 0 ? round((1 - $late_rate) * 100) : 100;

        // Actionable recommendation
        $recText = "Student is performing well and on track.";
        if ($missed > 0) {
            $recText = "Urgent: Follow up on $missed missed assignment" . ($missed > 1 ? 's' : '') . " to reclaim up to 20 penalty points.";
        } elseif ($quiz_taken > 0 && $quiz_avg < 0.60) {
            $recText = "Low quiz average ($quizPct%). Recommend reviewing module topic materials and scheduling a review.";
        } elseif ($assign_grd > 0 && $assign_avg < 0.60) {
            $recText = "Assignment grades require attention ($assignPct%). Provide feedback on rubric criteria.";
        } elseif ($attend_tot > 0 && $attend_rate < 0.50) {
            $recText = "Low attendance ($attendPct%). Encourage student to attend scheduled live lectures.";
        } elseif ($late_tot > 0 && $late_rate > 0.40) {
            $recText = "Frequent late submissions (" . round($late_rate * 100) . "%). Remind student of submission deadlines.";
        }

        // Format breakdown for frontend rendering
        $breakdown = [
            'quiz' => [
                'label'   => 'Quiz Performance',
                'value'   => $quiz_taken > 0 ? "$quizPct%" : 'No data',
                'penalty' => $quizPenalty,
                'max'     => 30,
                'taken'   => $quiz_taken,
                'total'   => $totalClassQuizzes ?: $quiz_taken,
                'pct'     => $quizPct,
                'detail'  => $totalClassQuizzes > 0 ? "$quiz_taken of $totalClassQuizzes quizzes taken (Avg: $quizPct%)" : ($quiz_taken > 0 ? "$quiz_taken taken (Avg: $quizPct%)" : "No quizzes taken yet"),
                'status'  => $quiz_taken > 0 ? ($quiz_avg >= 0.75 ? 'good' : ($quiz_avg >= 0.50 ? 'warn' : 'bad')) : 'neutral',
            ],
            'assignment_grade' => [
                'label'   => 'Assignment Grades',
                'value'   => $assign_grd > 0 ? "$assignPct%" : 'No data',
                'penalty' => $assignPenalty,
                'max'     => 25,
                'graded'  => $assign_grd,
                'total'   => $totalClassAssignments ?: $assign_grd,
                'pct'     => $assignPct,
                'detail'  => $totalClassAssignments > 0 ? "$assign_grd of $totalClassAssignments assignments graded (Avg: $assignPct%)" : ($assign_grd > 0 ? "$assign_grd graded (Avg: $assignPct%)" : "No graded submissions yet"),
                'status'  => $assign_grd > 0 ? ($assign_avg >= 0.75 ? 'good' : ($assign_avg >= 0.50 ? 'warn' : 'bad')) : 'neutral',
            ],
            'missed' => [
                'label'   => 'Missed Assignments',
                'value'   => $missed === 0 ? '0 missed' : "$missed missed",
                'penalty' => $missPenalty,
                'max'     => 20,
                'count'   => $missed,
                'pct'     => $missed === 0 ? 100 : max(0, 100 - ($missed * 25)),
                'detail'  => $missed === 0 ? 'All past-due assignments submitted' : "$missed overdue assignment" . ($missed > 1 ? 's' : '') . " not submitted",
                'status'  => $missed === 0 ? 'good' : ($missed <= 2 ? 'warn' : 'bad'),
            ],
            'attendance' => [
                'label'    => 'Live Attendance',
                'value'    => $attend_tot > 0 ? "$attendPct%" : 'No sessions',
                'penalty'  => $attendPenalty,
                'max'      => 15,
                'attended' => $attend_att,
                'total'    => $attend_tot,
                'pct'      => $attendPct,
                'detail'   => $attend_tot > 0 ? "$attend_att of $attend_tot live sessions attended ($attendPct%)" : "No live sessions conducted yet",
                'status'   => $attend_tot > 0 ? ($attend_rate >= 0.75 ? 'good' : ($attend_rate >= 0.50 ? 'warn' : 'bad')) : 'neutral',
            ],
            'late' => [
                'label'   => 'Late Submissions',
                'value'   => $late_tot > 0 ? "$late_subs of $late_tot" : 'No data',
                'penalty' => $latePenalty,
                'max'     => 10,
                'late'    => $late_subs,
                'total'   => $late_tot,
                'rate'    => round($late_rate * 100),
                'pct'     => $onTimePct,
                'detail'  => $late_tot > 0 ? ($late_subs === 0 ? "All $late_tot submissions submitted on time" : "$late_subs of $late_tot submissions turned in late") : "No assignment submissions yet",
                'status'  => $late_tot > 0 ? ($late_rate <= 0.20 ? 'good' : ($late_rate <= 0.50 ? 'warn' : 'bad')) : 'neutral',
            ]
        ];

        $student_list[$uc] = [
            'user_code'      => $s['user_code'],
            'first_name'     => $s['first_name'],
            'last_name'      => $s['last_name'],
            'section'        => $s['section'],
            'year_level'     => $s['year_level'],
            'rule_score'     => $rule_score,
            'recommendation' => $recText,
            'breakdown'      => $breakdown,
            'ml_active'      => false,
        ];

        $batch_payload[] = [
            'student_code' => $uc,
            'features' => [
                'quiz_avg_pct'   => $quiz_avg,
                'assign_avg_pct' => $assign_avg,
                'missed_count'   => $missed,
                'attend_rate'    => $attend_rate,
                'late_rate'      => $late_rate,
            ]
        ];
    }

    $ml_results = _ml_predict_batch($batch_payload);
    $ml_active = is_array($ml_results) && count($ml_results) > 0;

    $results = [];
    foreach ($student_list as $uc => $s_data) {
        $final_score = $s_data['rule_score'];
        $ml_data = [];

        if ($ml_active) {
            foreach ($ml_results as $ml_res) {
                if ($ml_res['student_code'] == $uc) {
                    $final_score = (int) round($s_data['rule_score'] * 0.60 + $ml_res['ml_score'] * 0.40);
                    $ml_data = [
                        'ml_active'     => true,
                        'ml_level'      => $ml_res['ml_level'],
                        'ml_label'      => $ml_res['ml_label'],
                        'ml_score'      => $ml_res['ml_score'],
                        'ml_confidence' => $ml_res['ml_confidence'],
                        'probabilities' => $ml_res['probabilities'],
                    ];
                    break;
                }
            }
        }

        $academicHealth = max(0, min(100, 100 - $final_score));
        $meta = _risk_meta($final_score);
        $results[] = array_merge($s_data, $meta, [
            'score'           => $final_score,
            'academic_health' => $academicHealth,
        ], $ml_data);
    }

    usort($results, fn($a, $b) => $b['score'] - $a['score']);
    return $results;
}

/**
 * Analyze a student's performance across all enrolled classes and generate custom recommendations.
 */
function cenlearn_student_recommendations($conn, $student_code): array {
    $uc = $conn->real_escape_string($student_code);
    
    // 1. Get all classes this student is enrolled in
    $res = $conn->query("
        SELECT c.id AS class_id, c.class_name, c.subject
        FROM class_members cm
        JOIN classes c ON cm.class_id = c.id
        WHERE cm.user_code = '$uc' AND (c.is_archived = 0 OR c.is_archived IS NULL)
    ");
    
    if (!$res || $res->num_rows === 0) {
        return [
            'status' => 'no_classes',
            'overall_risk' => 'on_track',
            'risk_score' => 0,
            'subjects' => [],
            'struggling_subject' => null,
            'recommendations' => [
                [
                    'type' => 'info',
                    'icon' => 'fa-info-circle',
                    'title' => "No Enrolled Classes",
                    'desc' => "You are not enrolled in any active classes currently. Discuss your enrollment with your advisor."
                ]
            ]
        ];
    }
    
    $subjects = [];
    $max_score = -1;
    $struggling_class = null;
    $total_risk = 0;
    
    while ($row = $res->fetch_assoc()) {
        $cid = intval($row['class_id']);
        $risk = cenlearn_risk_score($conn, $student_code, $cid);
        
        $subject_data = [
            'class_id'     => $cid,
            'class_name'   => $row['class_name'],
            'subject_code' => $row['subject'] ?: 'SUBJ',
            'score'        => $risk['score'],
            'level'        => $risk['level'],
            'label'        => $risk['label'],
            'color'        => $risk['color'],
            'bg'           => $risk['bg'],
            'textColor'    => $risk['textColor'],
            'breakdown'    => $risk['breakdown'],
        ];
        
        $subjects[] = $subject_data;
        $total_risk += $risk['score'];
        
        if ($risk['score'] > $max_score) {
            $max_score = $risk['score'];
            $struggling_class = $subject_data;
        }
    }
    
    usort($subjects, fn($a, $b) => $b['score'] - $a['score']);
    
    $avg_score = round($total_risk / count($subjects));
    $overall_meta = _risk_meta($avg_score);
    
    // 2. Generate customized advice based on the struggling subject's breakdowns
    $recs = [];
    if ($struggling_class && $struggling_class['score'] > 30) {
        $s_name = htmlspecialchars($struggling_class['class_name']);
        $breakdown = $struggling_class['breakdown'];
        
        $missed = $breakdown['missed']['count'] ?? 0;
        if ($missed > 0) {
            $recs[] = [
                'type' => 'danger',
                'icon' => 'fa-pencil-square-o',
                'title' => "Submit Missed Tasks in $s_name",
                'desc' => "You have <strong>$missed missed assignments</strong>. Submitting these immediately can reclaim up to 20 penalty points on your score."
            ];
        }
        
        $quiz_taken = $breakdown['quiz']['taken'] ?? 0;
        $quiz_val = $breakdown['quiz']['value'] ?? '0%';
        $quiz_avg = floatval(rtrim($quiz_val, '%')) / 100;
        if ($quiz_taken > 0 && $quiz_avg < 0.60) {
            $recs[] = [
                'type' => 'warning',
                'icon' => 'fa-book',
                'title' => "Improve Quiz Performance in $s_name",
                'desc' => "Your current quiz average is low (<strong>" . round($quiz_avg * 100, 1) . "%</strong>). We recommend checking class resources and practicing quiz materials before your next test."
            ];
        }
        
        $attend_total = $breakdown['attendance']['total'] ?? 0;
        $attend_val = $breakdown['attendance']['value'] ?? '0%';
        $attend_rate = floatval(rtrim($attend_val, '%')) / 100;
        if ($attend_total > 0 && $attend_rate < 0.75) {
            $recs[] = [
                'type' => 'info',
                'icon' => 'fa-video-camera',
                'title' => "Boost Virtual Attendance in $s_name",
                'desc' => "You've attended only <strong>" . round($attend_rate * 100) . "%</strong> of live classes. Joining sessions live allows you to participate in real-time discussions."
            ];
        }
        
        $late_rate = $breakdown['late']['rate'] ?? 0;
        if ($late_rate > 30) {
            $recs[] = [
                'type' => 'warning',
                'icon' => 'fa-clock-o',
                'title' => "Manage Submission Deadlines in $s_name",
                'desc' => "About <strong>$late_rate%</strong> of your assignment submissions are turned in late. Plan to start class tasks early to avoid late score deductions."
            ];
        }
    }
    
    if (empty($recs)) {
        $recs[] = [
            'type' => 'success',
            'icon' => 'fa-star',
            'title' => "Excellent Academic Standing!",
            'desc' => "You are performing incredibly well across all your enrolled courses. Keep up the consistent work, attend your live lectures, and maintain your excellent streak!"
        ];
    }
    
    return [
        'status'             => 'active',
        'overall_risk'       => $overall_meta['level'],
        'overall_label'      => $overall_meta['label'],
        'overall_color'      => $overall_meta['color'],
        'overall_bg'         => $overall_meta['bg'],
        'overall_textColor'  => $overall_meta['textColor'],
        'risk_score'         => $avg_score,
        'subjects'           => $subjects,
        'struggling_subject' => $struggling_class,
        'recommendations'    => $recs
    ];
}

endif;

// ── Topic Weakness Detection + ML Recommendations ─────────────────────────
// Reads topic_performance for a student (optionally per-class),
// calls the ML /topic_analytics endpoint, and returns structured
// weakness data + actionable recommendations.
// Falls back to rule-based analysis when the ML API is offline.

if (!defined('ML_TOPIC_URL')) {
    define('ML_TOPIC_URL', getenv('ML_TOPIC_URL') ?: 'http://127.0.0.1:5001/topic_analytics');
}

// ── International Standard Reference Generator ─────────────────────────────
// Maps student topic mastery to Bloom's Revised Taxonomy (2001), ACM/IEEE CC2020 Curricula
// Standards, and ABET Student Outcomes to generate pedagogically rigorous remediation plans.
function cenlearn_international_standard_reference($topic, $score_pct, $attempts = 1, $subject = ''): array {
    $score_pct = floatval($score_pct);
    $topicName = trim($topic ?: 'Topic Material');

    if ($score_pct < 40) {
        $bloom_level     = "Level 1-2: Remember & Understand";
        $bloom_desc      = "Cognitive Foundational Deficit (Fails recall & core conceptual definitions)";
        $standard_code   = "BLOOM-L1-L2 / ACM-CC2020-FND";
        $competency_name = "Core Conceptual Knowledge & Recall";
        $priority_label  = "Critical Remediation Required";
        $standard_rec    = "Student requires immediate foundational re-instruction aligned with Bloom's Taxonomy Level 1-2. Re-study primary module documents, define key terminology, and complete guided recall checks before re-attempting formative assessments.";
        $remedial_steps  = [
            'phase_1' => [
                'phase' => 'Phase 1: Knowledge Re-Acquisition',
                'action' => "Re-read tagged module material for '{$topicName}' and extract fundamental definitions/rules.",
                'standard' => "Bloom's Level 1 (Remembering)"
            ],
            'phase_2' => [
                'phase' => 'Phase 2: Diagnostic Error Analysis',
                'action' => "Review specific quiz questions missed in '{$topicName}' to resolve conceptual misconceptions.",
                'standard' => "Diagnostic Formative Feedback (IEEE LOM Standard)"
            ],
            'phase_3' => [
                'phase' => 'Phase 3: Benchmark Mastery Target',
                'action' => "Re-take topic practice quiz and target >= 75% accuracy to satisfy ABET SO-1 outcome.",
                'standard' => "ABET Criteria SO-1 (Knowledge Application)"
            ]
        ];
    } elseif ($score_pct < 60) {
        $bloom_level     = "Level 2-3: Understand & Apply";
        $bloom_desc      = "Procedural Application Gap (Understands basics but fails complex multi-step application)";
        $standard_code   = "BLOOM-L2-L3 / IEEE-CS-APP";
        $competency_name = "Procedural Execution & Problem Solving";
        $priority_label  = "High Priority Practice";
        $standard_rec    = "Student shows partial comprehension but lacks procedural consistency (Bloom's Level 2-3). Review worked examples in class modules and complete step-by-step problem sets to bridge the application gap.";
        $remedial_steps  = [
            'phase_1' => [
                'phase' => 'Phase 1: Procedural Review',
                'action' => "Study module example problems and trace application workflows for '{$topicName}'.",
                'standard' => "Bloom's Level 2 (Understanding)"
            ],
            'phase_2' => [
                'phase' => 'Phase 2: Targeted Problem Practice',
                'action' => "Solve practice problems and analyze quiz errors to improve speed and calculation accuracy.",
                'standard' => "ACM/IEEE Curricula Competency Practice"
            ],
            'phase_3' => [
                'phase' => 'Phase 3: Benchmark Mastery Target',
                'action' => "Achieve >= 75% on the next topic assessment to demonstrate procedural mastery.",
                'standard' => "ABET Criteria SO-2 (Problem Application)"
            ]
        ];
    } elseif ($score_pct < 75) {
        $bloom_level     = "Level 3-4: Apply & Analyze";
        $bloom_desc      = "Analytical Optimization Need (Minor logic slips or edge case misinterpretations)";
        $standard_code   = "BLOOM-L3-L4 / ACM-CS-ANA";
        $competency_name = "Analytical Rigor & Edge-Case Differentiation";
        $priority_label  = "Moderate Review";
        $standard_rec    = "Student has solid foundation but needs fine-tuning on edge cases and analytical distinctions (Bloom's Level 3-4). Review quiz distractor items and verify nuanced concepts in module slides.";
        $remedial_steps  = [
            'phase_1' => [
                'phase' => 'Phase 1: Nuance & Edge Cases',
                'action' => "Analyze complex scenarios and edge-case exceptions in '{$topicName}' lecture materials.",
                'standard' => "Bloom's Level 4 (Analyzing)"
            ],
            'phase_2' => [
                'phase' => 'Phase 2: Assessment Fine-Tuning',
                'action' => "Retake related quizzes to eliminate careless mistakes and verify reasoning.",
                'standard' => "Formative Mastery Validation"
            ],
            'phase_3' => [
                'phase' => 'Phase 3: Benchmark Mastery Target',
                'action' => "Attain >= 80% to reach high-proficiency honors threshold.",
                'standard' => "ABET Criteria SO-6 (Systematic Analysis)"
            ]
        ];
    } else {
        $bloom_level     = "Level 5-6: Evaluate & Create";
        $bloom_desc      = "Proficient / Mastered (Demonstrates critical evaluation and creative synthesis)";
        $standard_code   = "BLOOM-L5-L6 / ABET-EXC";
        $competency_name = "Synthesis, Design & Peer Mentorship";
        $priority_label  = "Mastery Maintained";
        $standard_rec    = "Student has met international proficiency standards (Bloom's Level 5-6). Encourage advanced application projects, peer tutoring, and complex problem synthesis.";
        $remedial_steps  = [
            'phase_1' => [
                'phase' => 'Phase 1: Advanced Synthesis',
                'action' => "Apply '{$topicName}' concepts to capstone exercises and real-world system designs.",
                'standard' => "Bloom's Level 6 (Creating)"
            ],
            'phase_2' => [
                'phase' => 'Phase 2: Knowledge Sharing',
                'action' => "Engage in peer discussion to reinforce conceptual mastery.",
                'standard' => "Collaborative Learning Standard"
            ],
            'phase_3' => [
                'phase' => 'Phase 3: Sustained Excellence',
                'action' => "Maintain >= 85% across cumulative midterm/final assessments.",
                'standard' => "ABET Criteria SO-3 & SO-5"
            ]
        ];
    }

    return [
        'framework'          => "Bloom's Revised Taxonomy (2001) & ACM/IEEE CC2020 Curricula Standards",
        'bloom_level'        => $bloom_level,
        'bloom_desc'         => $bloom_desc,
        'standard_code'      => $standard_code,
        'competency_name'    => $competency_name,
        'priority_label'     => $priority_label,
        'standard_rec'       => $standard_rec,
        'target_benchmark'   => '>= 75% Mastery Benchmark (ABET SO-1/SO-2)',
        'remedial_steps'     => $remedial_steps,
    ];
}

// ── ML Semantic Module Finder ────────────────────────────────────────────────
// For quizzes/assignments with no direct module_id, this function searches all
// accessible class_modules and returns the best-matching module using multi-signal
// keyword scoring on title, topic, filename, and quiz/assignment content.
//
// Algorithm (score-based ranking):
//   +12  pts  - module topic tag is found in assessment content
//   +10  pts  - module title found in assessment content
//   + 5  pts  - full assessment title found in module title/topic
//   + 3  pts  - each overlapping keyword (length >= 4) between module and assessment
//   + 2  pts  - module class_id matches assessment class_id (same class bonus)
//
function cenlearn_find_best_module($conn, $student_code, $class_id, $assessment_title, $assessment_content = '', $assessment_topic = ''): ?array {
    $uc   = $conn->real_escape_string($student_code);
    $cid  = intval($class_id);

    // Gather all accessible modules for this student in this (or any) class
    $sql = "SELECT cm.id, cm.title, cm.original_name, cm.topic, cm.class_id, c.class_name
            FROM class_modules cm
            JOIN classes c ON cm.class_id = c.id
            JOIN class_members mem ON mem.class_id = c.id AND mem.user_code = '{$uc}'";
    if ($cid > 0) {
        $sql .= " WHERE cm.class_id = {$cid}";
    }
    $sql .= " ORDER BY cm.uploaded_at DESC LIMIT 100";

    $res = $conn->query($sql);
    if (!$res || $res->num_rows === 0) return null;

    // Build a searchable content string from the assessment
    $rawContent = strtolower(
        $assessment_title . ' ' .
        $assessment_content . ' ' .
        $assessment_topic
    );

    // Tokenize into meaningful keywords (length >= 4, not stop words)
    $stopwords = ['the','and','for','are','that','this','with','have','from','they','will',
                  'been','their','said','each','which','does','what','were','when','your',
                  'quiz','exam','test','assignment','task','activity','review','class','answer',
                  'question','questions','score','point','total','item','items','student','please'];
    $rawTokens = preg_split('/[\s,\.\-_\/\(\)\:\;\"\'\!\?]+/', $rawContent);
    $contentKeywords = [];
    foreach ($rawTokens as $t) {
        $t = trim(strtolower($t));
        if (strlen($t) >= 4 && !in_array($t, $stopwords)) {
            $contentKeywords[$t] = true;
        }
    }

    $bestModule = null;
    $bestScore  = -1;

    while ($m = $res->fetch_assoc()) {
        $mTitle   = strtolower($m['title'] ?? '');
        $mTopic   = strtolower($m['topic'] ?? '');
        $mName    = strtolower($m['original_name'] ?? '');
        $mClassId = intval($m['class_id']);
        $score    = 0;

        // Signal 1: Module topic tag found verbatim in assessment content
        if (!empty($mTopic) && strpos($rawContent, $mTopic) !== false) $score += 12;

        // Signal 2: Module title found verbatim in assessment content
        if (!empty($mTitle) && strpos($rawContent, $mTitle) !== false) $score += 10;

        // Signal 3: Assessment title found in module title or topic
        $aTitle = strtolower($assessment_title);
        if ($aTitle && (strpos($mTitle, $aTitle) !== false || strpos($mTopic, $aTitle) !== false)) $score += 5;

        // Signal 4: Keyword overlap between module metadata and assessment content
        $modWords = preg_split('/[\s,\.\-_\/\(\)\:\;\"\'\!\?]+/', $mTitle . ' ' . $mTopic . ' ' . $mName);
        foreach ($modWords as $w) {
            $w = trim(strtolower($w));
            if (strlen($w) >= 4 && isset($contentKeywords[$w])) $score += 3;
        }

        // Signal 5: Same class bonus
        if ($cid > 0 && $mClassId === $cid) $score += 2;

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestModule = [
                'id'            => intval($m['id']),
                'title'         => $m['title'],
                'original_name' => $m['original_name'],
                'class_id'      => $mClassId,
                'class_name'    => $m['class_name'],
                'topic'         => $m['topic'],
                'match_score'   => $score,
                'auto_matched'  => true,   // flag: not directly linked, was found by ML matcher
            ];
        }
    }

    // Always return the best-scoring module found.
    // If no semantic signal matched (score <= 2) but a module from the same class
    // was found (score = 2 from the class bonus), still return it so the student
    // always sees a specific teacher-uploaded module rather than a generic button.
    return $bestModule;
}

// ── Assignment-Level Topic Performance with Module Matching ──────────────────
// Returns failed assignments with their best-matched modules for remediation.
function cenlearn_assignment_module_recommendations($conn, $student_code, $class_id = null): array {
    $uc        = $conn->real_escape_string($student_code);
    $cidFilter = $class_id ? 'AND a.class_id = '.intval($class_id) : '';

    // Fetch assignment submissions where student scored below 75% or was graded low
    $res = $conn->query("
        SELECT a.id AS assignment_id,
               a.title AS assignment_title,
               a.instructions,
               a.points AS max_points,
               a.class_id,
               c.class_name,
               c.subject,
               sub.grade,
               sub.submitted_at,
               ROUND((sub.grade / NULLIF(a.points,0)) * 100, 1) AS score_pct
        FROM assignment_submissions sub
        JOIN assignments a ON sub.assignment_id = a.id
        JOIN classes c ON a.class_id = c.id
        WHERE sub.student_code = '$uc'
          AND sub.grade IS NOT NULL
          AND a.points > 0
          $cidFilter
        ORDER BY score_pct ASC
        LIMIT 20
    ");

    $items = [];
    if (!$res || $res->num_rows === 0) return $items;

    while ($row = $res->fetch_assoc()) {
        $pct = floatval($row['score_pct']);
        if ($pct >= 75) continue; // Only flag underperforming ones

        // Try to find a matching module using the semantic finder
        $matched = cenlearn_find_best_module(
            $conn,
            $student_code,
            intval($row['class_id']),
            $row['assignment_title'],
            $row['instructions'] ?? '',
            ''
        );

        $items[] = [
            'type'           => 'assignment',
            'assignment_id'  => intval($row['assignment_id']),
            'title'          => $row['assignment_title'],
            'class_id'       => intval($row['class_id']),
            'class_name'     => $row['class_name'],
            'subject'        => $row['subject'] ?? $row['class_name'],
            'score_pct'      => $pct,
            'max_points'     => floatval($row['max_points']),
            'earned'         => floatval($row['grade']),
            'submitted_at'   => $row['submitted_at'],
            'matched_module' => $matched,
        ];
    }

    return $items;
}

/**
 * Compute student's holistic overall academic performance across all enrolled classes
 * including cumulative quiz scores, assignment grades, active classes, completed lessons, and study engagement.
 */
function cenlearn_overall_student_performance($conn, $student_code): array {
    $uc = $conn->real_escape_string($student_code);

    // 1. Quizzes summary across all classes
    $qRes = $conn->query("
        SELECT COUNT(DISTINCT qs.quiz_id) AS total_quizzes_taken,
               SUM(qs.score) AS total_earned_points,
               SUM(qs.total_points) AS total_max_points
        FROM quiz_submissions qs
        WHERE qs.student_code = '$uc'
    ");
    $qData = $qRes ? $qRes->fetch_assoc() : [];
    $quizzes_taken = intval($qData['total_quizzes_taken'] ?? 0);
    $quiz_earned   = floatval($qData['total_earned_points'] ?? 0);
    $quiz_max      = floatval($qData['total_max_points'] ?? 0);
    $quiz_pct      = $quiz_max > 0 ? round(($quiz_earned / $quiz_max) * 100, 1) : null;

    // 2. Assignments summary across all classes
    $aRes = $conn->query("
        SELECT COUNT(DISTINCT sub.assignment_id) AS total_assignments_submitted,
               SUM(sub.grade) AS total_earned_points,
               SUM(a.points) AS total_max_points
        FROM assignment_submissions sub
        JOIN assignments a ON sub.assignment_id = a.id
        WHERE sub.student_code = '$uc' AND sub.grade IS NOT NULL
    ");
    $aData = $aRes ? $aRes->fetch_assoc() : [];
    $assignments_submitted = intval($aData['total_assignments_submitted'] ?? 0);
    $assign_earned        = floatval($aData['total_earned_points'] ?? 0);
    $assign_max           = floatval($aData['total_max_points'] ?? 0);
    $assign_pct           = $assign_max > 0 ? round(($assign_earned / $assign_max) * 100, 1) : null;

    // 3. Enrolled active classes count
    $cRes = $conn->query("
        SELECT COUNT(cm.id) AS enrolled_count
        FROM class_members cm
        JOIN classes c ON cm.class_id = c.id
        WHERE cm.user_code = '$uc' AND (c.is_archived = 0 OR c.is_archived IS NULL)
    ");
    $enrolled_count = intval(($cRes ? $cRes->fetch_assoc()['enrolled_count'] : 0));

    // 4. Completed lessons / modules count
    $lRes = $conn->query("
        SELECT COUNT(id) AS completed_lessons
        FROM lesson_progress
        WHERE student_code = '$uc' AND status = 'completed'
    ");
    $completed_lessons = intval(($lRes ? $lRes->fetch_assoc()['completed_lessons'] : 0));

    // Calculate Overall Weighted Score
    $overall_pct = 75.0; // default baseline
    if ($quiz_pct !== null && $assign_pct !== null) {
        $overall_pct = round(($quiz_pct * 0.5) + ($assign_pct * 0.5), 1);
    } elseif ($quiz_pct !== null) {
        $overall_pct = $quiz_pct;
    } elseif ($assign_pct !== null) {
        $overall_pct = $assign_pct;
    }

    // Determine performance standing classification
    if ($overall_pct < 50) {
        $standing = "Critical Remediation Required";
        $badge    = "Action Required (Overall: {$overall_pct}%)";
        $color    = "#ef4444";
        $bg       = "#fef2f2";
        $border   = "#fecaca";
        $icon     = "fa-exclamation-triangle";
    } elseif ($overall_pct < 75) {
        $standing = "Developing Standing";
        $badge    = "Developing (Overall: {$overall_pct}%)";
        $color    = "#f59e0b";
        $bg       = "#fffbeb";
        $border   = "#fde68a";
        $icon     = "fa-bar-chart";
    } elseif ($overall_pct < 90) {
        $standing = "Proficient Academic Standing";
        $badge    = "Proficiency Met (Overall: {$overall_pct}%)";
        $color    = "#3b82f6";
        $bg       = "#eff6ff";
        $border   = "#bfdbfe";
        $icon     = "fa-check-circle";
    } else {
        $standing = "Exemplary Honors Performance";
        $badge    = "Top Performer (Overall: {$overall_pct}%)";
        $color    = "#10b981";
        $bg       = "#f0fdf4";
        $border   = "#bbf7d0";
        $icon     = "fa-trophy";
    }

    // Generate personalized AI Overall Strategy & Guidance
    $ai_message = "Your overall cumulative academic average across <strong>{$enrolled_count} active class" . ($enrolled_count !== 1 ? 'es' : '') . "</strong> is <strong>{$overall_pct}%</strong> ({$standing}).";
    
    if ($quiz_pct !== null && $assign_pct !== null) {
        $ai_message .= " Your assignment average is <strong>{$assign_pct}%</strong> based on {$assignments_submitted} task" . ($assignments_submitted !== 1 ? 's' : '') . ", while your quiz mastery average is <strong>{$quiz_pct}%</strong> across {$quizzes_taken} quiz" . ($quizzes_taken !== 1 ? 'zes' : '') . ".";
        if ($quiz_pct < $assign_pct) {
            $ai_message .= " Your primary opportunity for overall GPA improvement is boosting your quiz performance through targeted module reviews and practice quizzes.";
        } else {
            $ai_message .= " Your quiz foundation is strong. Maintain high assignment submission quality and submit remaining coursework on time.";
        }
    } elseif ($quiz_pct !== null) {
        $ai_message .= " Your cumulative quiz score is <strong>{$quiz_pct}%</strong> across {$quizzes_taken} completed quiz" . ($quizzes_taken !== 1 ? 'zes' : '') . ". Keep completing class assignments to build your overall grade profile.";
    } elseif ($assign_pct !== null) {
        $ai_message .= " Your assignment average is <strong>{$assign_pct}%</strong> across {$assignments_submitted} submitted task" . ($assignments_submitted !== 1 ? 's' : '') . ". Take upcoming class quizzes to establish your full academic mastery score.";
    } else {
        $ai_message .= " Start taking quizzes and submitting assignments to build your personalized AI performance tracking and overall study recommendations.";
    }

    // Recommended Action Pathway
    if ($overall_pct < 75) {
        $action = "Follow your 3-step overall action plan: 1. Review weak subject modules, 2. Retake practice quizzes to reach 75%+ benchmark, 3. Complete pending assignment tasks.";
    } else {
        $action = "Maintain your high overall academic streak with spaced topic repetition and capstone project synthesis.";
    }

    return [
        'overall_pct'           => $overall_pct,
        'quiz_pct'              => $quiz_pct,
        'assign_pct'            => $assign_pct,
        'quizzes_taken'         => $quizzes_taken,
        'assignments_submitted' => $assignments_submitted,
        'enrolled_count'        => $enrolled_count,
        'completed_lessons'     => $completed_lessons,
        'standing'              => $standing,
        'badge'                 => $badge,
        'color'                 => $color,
        'bg'                    => $bg,
        'border'                => $border,
        'icon'                  => $icon,
        'message'               => $ai_message,
        'action'                => $action,
    ];
}

// ── AI Performance Coach & Diagnostic Insight Engine ────────────────────────
// Replaces generic quotes with personalized, data-grounded AI diagnostic feedback
// based on the student's actual quiz scores, essay evaluation, and weak concepts.
function cenlearn_get_motivational_quote($score_pct, $weakest_topic = '', $subject_name = '', $quiz_title = '', $module_title = '', $missing_concepts = ''): array {
    $score = floatval($score_pct);
    $topicName = htmlspecialchars(trim($weakest_topic ?: 'General Coursework'));
    $subjName = htmlspecialchars(trim($subject_name ?: 'Your Enrolled Subject'));
    $qTitle = htmlspecialchars(trim($quiz_title ?: 'recent assessment'));
    $mTitle = htmlspecialchars(trim($module_title ?: 'connected learning module'));

    if ($score < 50) {
        $title = "AI Diagnostic Coach: Critical Remediation Alert";
        $badge = "Action Required (Score: {$score}%)";
        $color = "#ef4444";
        $bg = "#fef2f2";
        $border = "#fecaca";
        $icon = "fa-exclamation-triangle";
        
        $msg = "In your assessment on <strong>{$subjName}</strong> ({$topicName}), your score is <strong>{$score}%</strong>. Diagnostic analysis of your quiz and essay submissions revealed foundational conceptual gaps.";
        if (!empty($missing_concepts)) {
            $msg .= " Specifically, key concepts in <em>{$missing_concepts}</em> were missing from your responses.";
        }
        $msg .= " Don't get discouraged! We mapped your weak questions directly to source module <strong>{$mTitle}</strong>. Study this module and retake your practice quiz to build your foundation and reach the 75%+ benchmark!";
        $action = "Study <strong>{$mTitle}</strong> in Phase 1, review missed questions in Phase 2, and retake the quiz to achieve mastery.";
    } elseif ($score < 75) {
        $title = "AI Learning Coach: Targeted Practice & Skill Reinforcement";
        $badge = "Developing (Score: {$score}%)";
        $color = "#f59e0b";
        $bg = "#fffbeb";
        $border = "#fde68a";
        $icon = "fa-bolt";

        $msg = "You scored <strong>{$score}%</strong> on <strong>{$subjName}</strong> ({$topicName}), which is just below the 75% mastery benchmark. Your quiz and essay responses showed good effort but missed key conceptual points.";
        if (!empty($missing_concepts)) {
            $msg .= " Reviewing concepts around <em>{$missing_concepts}</em> will help solidify your answers.";
        }
        $msg .= " Study the linked source module <strong>{$mTitle}</strong> to close these concept gaps and achieve mastery!";
        $action = "Follow the 3-phase pathway: review <strong>{$mTitle}</strong>, analyze missed quiz items, and retake the practice quiz.";
    } elseif ($score < 90) {
        $title = "AI Performance Coach: Excellence & Advanced Growth";
        $badge = "Proficiency Met (Score: {$score}%)";
        $color = "#3b82f6";
        $bg = "#eff6ff";
        $border = "#bfdbfe";
        $icon = "fa-check-circle";
        $quote = "“Excellence is not an accomplishment, but a continuous journey of learning and refinement.”";

        $msg = "<div style='font-style:italic;color:#1e40af;margin-bottom:6px;font-weight:600;'><i class='fa fa-quote-left'></i> {$quote} <i class='fa fa-quote-right'></i></div>"
             . "Great work! You scored <strong>{$score}%</strong> in <strong>{$subjName}</strong> ({$topicName}). You demonstrated solid conceptual understanding across your quiz and assignment assessments.<br>"
             . "<div style='margin-top:6px;font-size:12px;color:#334155;'><strong>How to further elevate your performance:</strong>"
             . "<ul style='margin:4px 0 0 16px;padding:0;line-height:1.45;'>"
             . "<li><strong>Quizzes:</strong> Practice timed analytical questions and master edge-case question variations.</li>"
             . "<li><strong>Assignments:</strong> Add empirical evidence, structured references, and in-depth critical analysis.</li>"
             . "<li><strong>Course Mastery:</strong> Engage in spaced repetition of module concepts to retain knowledge for final exams.</li>"
             . "</ul></div>";
        $action = "Sustain your high momentum with spaced practice and take on advanced topic challenges.";
    } else {
        $title = "AI Performance Coach: Exemplary Honors Mastery";
        $badge = "Top Performer (Score: {$score}%)";
        $color = "#10b981";
        $bg = "#f0fdf4";
        $border = "#bbf7d0";
        $icon = "fa-trophy";
        $quote = "“Success is where preparation and opportunity meet. Strive not just for mastery, but for meaningful impact.”";

        $msg = "<div style='font-style:italic;color:#065f46;margin-bottom:6px;font-weight:600;'><i class='fa fa-quote-left'></i> {$quote} <i class='fa fa-quote-right'></i></div>"
             . "Outstanding performance! You achieved <strong>{$score}%</strong> in <strong>{$subjName}</strong> ({$topicName}), demonstrating complete mastery across your quiz and assignment evaluations.<br>"
             . "<div style='margin-top:6px;font-size:12px;color:#334155;'><strong>Continuous Growth Roadmap:</strong>"
             . "<ul style='margin:4px 0 0 16px;padding:0;line-height:1.45;'>"
             . "<li><strong>Quizzes:</strong> Maintain perfect accuracy and assist classmates in understanding complex topics.</li>"
             . "<li><strong>Assignments:</strong> Turn top-scoring assignments into portfolio assets for your capstone project.</li>"
             . "<li><strong>Leadership:</strong> Actively participate in peer mentorship and explore advanced industry case studies.</li>"
             . "</ul></div>";
        $action = "Lead peer study discussions, explore capstone synthesis, and sustain honors-level consistency.";
    }

    return [
        'title'            => $title,
        'badge'            => $badge,
        'color'            => $color,
        'bg'               => $bg,
        'border'           => $border,
        'icon'             => $icon,
        'quote'            => $quote ?? '',
        'message'          => $msg,
        'action'           => $action,
        'score'            => $score,
        'topic'            => $topicName,
        'subject'          => $subjName,
        'module'           => $mTitle,
        'quiz_title'       => $qTitle,
        'missing_concepts' => $missing_concepts
    ];
}

function cenlearn_topic_recommendations($conn, $student_code, $class_id = null): array {
    $uc        = $conn->real_escape_string($student_code);
    $cidFilter = $class_id ? 'AND tp.class_id = '.intval($class_id) : '';

    // Pull all topic scores for this student
    $res = $conn->query("
        SELECT tp.topic,
               tp.class_id,
               c.class_name,
               c.subject,
               ROUND((tp.total_points_earned / NULLIF(tp.total_points_available,0)) * 100, 1) AS score_pct,
               tp.attempts,
               tp.last_attempt
        FROM topic_performance tp
        JOIN classes c ON tp.class_id = c.id
        WHERE tp.student_code = '$uc'
          AND tp.total_points_available > 0
          $cidFilter
        ORDER BY score_pct ASC
    ");

    // Fetch ALL accessible modules, indexed by ID and keywords
    $modRes = $conn->query("
        SELECT cm.id, cm.title AS mod_title, cm.original_name, cm.class_id,
               c.class_name AS mod_class_name,
               LOWER(TRIM(COALESCE(cm.topic, ''))) AS topic_key,
               LOWER(TRIM(COALESCE(cm.title, ''))) AS title_key,
               LOWER(TRIM(COALESCE(cm.original_name, ''))) AS fname_key
        FROM class_modules cm
        JOIN classes c ON cm.class_id = c.id
        JOIN class_members mem ON mem.class_id = c.id AND mem.user_code = '$uc'
        ORDER BY cm.uploaded_at DESC
    ");
    $modulesById = [];
    $modulesByTopic = [];
    $allAccessibleModules = [];
    if($modRes){
        while($mr = $modRes->fetch_assoc()){
            $mId = intval($mr['id']);
            $modItem = [
                'id'            => $mId,
                'title'         => $mr['mod_title'],
                'original_name' => $mr['original_name'],
                'class_id'      => intval($mr['class_id']),
                'class_name'    => $mr['mod_class_name'],
                'topic_key'     => $mr['topic_key'],
                'title_key'     => $mr['title_key'],
                'fname_key'     => $mr['fname_key']
            ];
            $modulesById[$mId] = $modItem;
            $allAccessibleModules[] = $modItem;
            if(!empty($mr['topic_key'])){
                $key = $mr['topic_key'];
                if(!isset($modulesByTopic[$key])) $modulesByTopic[$key] = [];
                $modulesByTopic[$key][] = $modItem;
            }
        }
    }

    if(!$res || $res->num_rows === 0){
        $default_quote = cenlearn_get_motivational_quote(75.0);
        return [
            'has_data'            => false,
            'weak_topics'         => [],
            'strong_topics'       => [],
            'recommendations'     => [],
            'ml_active'           => false,
            'motivational_quote'  => $default_quote,
            'overall_performance' => cenlearn_overall_student_performance($conn, $student_code),
        ];
    }

    $all_topics = [];
    $ml_payload = [];
    while($r = $res->fetch_assoc()){
        $pct = floatval($r['score_pct']);
        $stdRef = cenlearn_international_standard_reference($r['topic'], $pct, intval($r['attempts']), $r['subject']);
        $all_topics[] = [
            'topic'        => $r['topic'],
            'class_id'     => intval($r['class_id']),
            'class_name'   => $r['class_name'],
            'subject'      => $r['subject'] ?: $r['class_name'],
            'score_pct'    => $pct,
            'attempts'     => intval($r['attempts']),
            'last_attempt' => $r['last_attempt'],
            'standard_ref' => $stdRef
        ];
        $ml_payload[] = [
            'topic'     => $r['topic'],
            'score_pct' => $pct,
            'attempts'  => intval($r['attempts']),
        ];
    }

    // Fetch quiz-level context per topic including exact quiz_module_id and essay_feedback
    $quizCtxRes = $conn->query("
        SELECT q.id AS quiz_id,
               q.title AS quiz_title,
               q.module_id AS quiz_module_id,
               c.class_name,
               c.id AS class_id,
               qs.score AS earned,
               qs.total_points AS total,
               qs.essay_feedback,
               LOWER(TRIM(qq.topic)) AS topic_key,
               ROUND(qs.score / NULLIF(qs.total_points,0) * 100, 1) AS overall_pct,
               qs.submitted_at
        FROM quiz_submissions qs
        JOIN quizzes q ON qs.quiz_id = q.id
        JOIN classes c ON q.class_id = c.id
        JOIN (
            SELECT DISTINCT quiz_id, LOWER(TRIM(topic)) AS topic
            FROM quiz_questions
            WHERE topic IS NOT NULL AND topic != ''
        ) qq ON qq.quiz_id = q.id
        WHERE qs.student_code = '$uc'
        ORDER BY qs.submitted_at DESC
    ");
    $quizCtxByTopic = [];
    if($quizCtxRes){
        while($qr = $quizCtxRes->fetch_assoc()){
            $key = $qr['topic_key'];
            if(!isset($quizCtxByTopic[$key])) $quizCtxByTopic[$key] = [];
            foreach($quizCtxByTopic[$key] as $existing){
                if($existing['quiz_id'] == $qr['quiz_id']) continue 2;
            }
            $overall = floatval($qr['overall_pct']);
            $quizCtxByTopic[$key][] = [
                'quiz_id'        => intval($qr['quiz_id']),
                'quiz_title'     => $qr['quiz_title'],
                'quiz_module_id' => intval($qr['quiz_module_id'] ?? 0),
                'class_name'     => $qr['class_name'],
                'class_id'       => intval($qr['class_id']),
                'earned'         => floatval($qr['earned']),
                'total'          => floatval($qr['total']),
                'overall_pct'    => $overall,
                'gap_pct'        => max(0, 100 - $overall),
                'essay_feedback' => $qr['essay_feedback'],
                'submitted_at'   => $qr['submitted_at']
            ];
        }
    }

    // Attach modules + quiz context to every topic entry
    foreach($all_topics as &$t){
        $key = strtolower(trim($t['topic']));
        $matchedMods = [];
        $tQuizCtx = $quizCtxByTopic[$key] ?? [];

        // 1. Direct module from quiz_module_id (quiz was directly generated from a module)
        if (!empty($tQuizCtx)) {
            foreach ($tQuizCtx as $qItem) {
                if (!empty($qItem['quiz_module_id']) && isset($modulesById[$qItem['quiz_module_id']])) {
                    if (!in_array($modulesById[$qItem['quiz_module_id']], $matchedMods))
                        $matchedMods[] = $modulesById[$qItem['quiz_module_id']];
                }
            }
        }

        // 2. ML Semantic Fallback: for quizzes with no module_id, find the best matching module
        //    by scoring quiz title + topic against all class modules with keyword overlap
        if (empty($matchedMods) && !empty($tQuizCtx)) {
            foreach (array_slice($tQuizCtx, 0, 2) as $qItem) {
                $autoMod = cenlearn_find_best_module(
                    $conn,
                    $student_code,
                    $qItem['class_id'] ?: $t['class_id'],
                    $qItem['quiz_title'],
                    '',
                    $t['topic']
                );
                if ($autoMod && !in_array($autoMod, $matchedMods)) {
                    $matchedMods[] = $autoMod;
                }
            }
        }

        // 3. Direct topic tag match on module's topic field
        if (isset($modulesByTopic[$key])) {
            foreach ($modulesByTopic[$key] as $m) {
                if (!in_array($m, $matchedMods)) $matchedMods[] = $m;
            }
        }

        // 4. Keyword / title / filename match in same class
        if (empty($matchedMods) && !empty($allAccessibleModules)) {
            foreach ($allAccessibleModules as $aMod) {
                if ($aMod['class_id'] === $t['class_id'] || empty($t['class_id'])) {
                    if (strpos($aMod['title_key'], $key) !== false || strpos($key, $aMod['title_key']) !== false ||
                        strpos($aMod['fname_key'], $key) !== false || strpos($aMod['topic_key'], $key) !== false) {
                        if (!in_array($aMod, $matchedMods)) $matchedMods[] = $aMod;
                    }
                }
            }
        }

        // 5. Class-level fallback: always give student at least one module to study
        if (empty($matchedMods) && !empty($allAccessibleModules)) {
            foreach ($allAccessibleModules as $aMod) {
                if ($aMod['class_id'] === $t['class_id']) {
                    if (!in_array($aMod, $matchedMods)) $matchedMods[] = $aMod;
                }
            }
        }

        $t['modules']      = $matchedMods;
        $t['quiz_context'] = array_slice($tQuizCtx, 0, 3);
    }
    unset($t);

    // Build weak / strong topic lists
    $weak_topics   = array_filter($all_topics, fn($t) => $t['score_pct'] < 75);
    $strong_topics = array_filter($all_topics, fn($t) => $t['score_pct'] >= 75);
    usort($weak_topics,   fn($a,$b) => $a['score_pct'] <=> $b['score_pct']);
    usort($strong_topics, fn($a,$b) => $b['score_pct'] <=> $a['score_pct']);

    // Generate accurate, module-grounded recommendations
    $recommendations = [];

    foreach(array_slice($weak_topics, 0, 3) as $wt){
        $pct      = $wt['score_pct'];
        $priority = $pct < 50 ? 'High' : 'Medium';
        $type     = $pct < 50 ? 'danger' : 'warning';
        $icon     = $pct < 50 ? 'fa-exclamation-circle' : 'fa-pencil';
        $attempts = $wt['attempts'];
        $std_ref  = $wt['standard_ref'];
        $topMod   = !empty($wt['modules'][0]) ? $wt['modules'][0] : null;
        $modTitle = $topMod ? $topMod['title'] : 'Class Learning Module';
        $topQuiz  = !empty($wt['quiz_context'][0]) ? $wt['quiz_context'][0] : null;
        $quizTitle = $topQuiz ? $topQuiz['quiz_title'] : 'Assessment';
        $quizId    = $topQuiz ? $topQuiz['quiz_id'] : null;

        // Parse missing concepts from essay feedback if available
        $missingConceptsStr = '';
        if ($topQuiz && !empty($topQuiz['essay_feedback'])) {
            $decodedFb = json_decode($topQuiz['essay_feedback'], true);
            if (is_array($decodedFb)) {
                $concepts = [];
                foreach ($decodedFb as $fbItem) {
                    if (is_array($fbItem) && !empty($fbItem['missing_concepts'])) {
                        foreach ($fbItem['missing_concepts'] as $mc) $concepts[] = is_array($mc) ? ($mc['concept']??'') : strval($mc);
                    }
                }
                if (!empty($concepts)) $missingConceptsStr = implode(', ', array_slice(array_unique(array_filter($concepts)), 0, 3));
            }
        }

        $diagDesc = "In assessment <strong>".htmlspecialchars($quizTitle)."</strong>, your score was <strong>{$pct}%</strong> after {$attempts} attempt".($attempts!==1?'s':'').".";
        if (!empty($missingConceptsStr)) {
            $diagDesc .= " AI analysis detected concept gaps in <em>".htmlspecialchars($missingConceptsStr)."</em>.";
        }
        if ($topMod) {
            $modTitle  = $topMod['title'];
            $diagDesc .= " Review the teacher-uploaded module <strong>".htmlspecialchars($modTitle)."</strong> to study definitions and worked examples, then retake the quiz to achieve &ge; 75% mastery.";
        } else {
            $diagDesc .= " Your teacher has not yet uploaded a learning module for this class. Ask your teacher to upload a module so you can review the relevant topics and improve your score.";
        }

        $recommendations[] = [
            'type'             => $type,
            'icon'             => $icon,
            'title'            => 'Improve: '.htmlspecialchars($wt['topic']).' in <strong>'.htmlspecialchars($wt['subject']).'</strong>',
            'desc'             => $diagDesc,
            'score_pct'        => $pct,
            'topic'            => $wt['topic'],
            'priority'         => $priority,
            'ml_driven'        => true,
            'modules'          => $wt['modules'],
            'primary_module'   => $topMod,
            'quiz_context'     => $wt['quiz_context'],
            'quiz_id'          => $quizId,
            'quiz_title'       => $quizTitle,
            'missing_concepts' => $missingConceptsStr,
            'standard_ref'     => $std_ref,
            'class_id'         => $wt['class_id'] ?? null,
            'class_name'       => $wt['class_name'] ?? null,
            'subject'          => $wt['subject'] ?? null,
        ];
    }

    if(!empty($strong_topics)){
        $top   = array_slice($strong_topics, 0, 2);
        $names = implode(' & ', array_map(fn($t) => '<strong>'.htmlspecialchars($t['topic']).'</strong>', $top));
        $stModules = [];
        $stClassId = null;
        $stSubject = null;
        $stClassName = null;
        foreach ($top as $st) {
            if (!empty($st['modules'])) {
                foreach ($st['modules'] as $m) $stModules[] = $m;
            }
            if (!$stClassId && !empty($st['class_id'])) {
                $stClassId = $st['class_id'];
                $stSubject = $st['subject'];
                $stClassName = $st['class_name'];
            }
        }
        $avgScore = round(array_sum(array_column($top, 'score_pct')) / count($top), 1);
        $recommendations[] = [
            'type'             => 'success',
            'icon'             => 'fa-star',
            'title'            => 'Outstanding Performance in '.$names.' ('.$avgScore.'%)',
            'desc'             => 'You are performing above international proficiency standards (Bloom Level 5-6: Synthesis & Evaluation). Maintain this momentum by engaging in deeper analytical problem-solving and peer leadership.',
            'score_pct'        => $avgScore,
            'topic'            => $top[0]['topic'] ?? 'Course Mastery',
            'priority'         => 'Low',
            'ml_driven'        => true,
            'modules'          => array_slice($stModules, 0, 4),
            'primary_module'   => $stModules[0] ?? null,
            'quiz_context'     => [],
            'quiz_id'          => null,
            'quiz_title'       => null,
            'missing_concepts' => null,
            'standard_ref'     => cenlearn_international_standard_reference('General Mastery', $avgScore, 1, $stSubject ?? ''),
            'class_id'         => $stClassId,
            'class_name'       => $stClassName,
            'subject'          => $stSubject,
        ];
    }

    if(empty($recommendations)){
        $recommendations[] = [
            'type'             => 'info',
            'icon'             => 'fa-info-circle',
            'title'            => 'No topic data yet',
            'desc'             => 'Complete quizzes with tagged topics to see personalized recommendations and module connections.',
            'score_pct'        => null,
            'topic'            => null,
            'priority'         => 'Low',
            'ml_driven'        => false,
            'modules'          => [],
            'primary_module'   => null,
            'quiz_context'     => [],
            'quiz_id'          => null,
            'quiz_title'       => null,
            'missing_concepts' => null,
            'standard_ref'     => null,
            'class_id'         => null,
            'class_name'       => null,
            'subject'          => null,
        ];
    }

    // ── Assignment-level recommendations with ML module matching ─────────────
    // For assignments (which have no module_id column), use the semantic module
    // finder to recommend the best matching module for each failed assignment.
    $assignment_recs = cenlearn_assignment_module_recommendations($conn, $student_code, $class_id);

    // Merge critical assignment fails (< 50%) into the top recommendations
    foreach (array_slice($assignment_recs, 0, 2) as $ar) {
        $arPct  = $ar['score_pct'];
        $arMod  = $ar['matched_module'];
        $arType = $arPct < 50 ? 'danger' : 'warning';
        $arIcon = $arPct < 50 ? 'fa-exclamation-circle' : 'fa-pencil';
        $arPri  = $arPct < 50 ? 'High' : 'Medium';

        $arModTitle = $arMod ? $arMod['title'] : 'a relevant class learning module';
        $autoTag = $arMod && !empty($arMod['auto_matched']) ? ' <em style="font-size:10.5px;color:#6366f1;">(ML-Matched)</em>' : '';

        $arDesc = "Your assignment <strong>".htmlspecialchars($ar['title'])."</strong> scored <strong>{$arPct}%</strong>."
                . ($arMod
                    ? " The ML module finder identified <strong>".htmlspecialchars($arModTitle)."</strong> as the most relevant source module for this assignment topic. Study it to improve your conceptual foundation and resubmit a stronger answer."
                    : " No directly linked module was found, but reviewing all available class materials will help improve your next submission."
                );

        $recommendations[] = [
            'type'             => $arType,
            'icon'             => $arIcon,
            'title'            => '<i class="fa fa-pencil-square-o"></i> Assignment: '.htmlspecialchars($ar['title']).' in <strong>'.htmlspecialchars($ar['subject']).'</strong>'.$autoTag,
            'desc'             => $arDesc,
            'score_pct'        => $arPct,
            'topic'            => null,
            'priority'         => $arPri,
            'ml_driven'        => true,
            'modules'          => $arMod ? [$arMod] : [],
            'primary_module'   => $arMod,
            'quiz_context'     => [],
            'quiz_id'          => null,
            'quiz_title'       => null,
            'missing_concepts' => null,
            'standard_ref'     => null,
            'class_id'         => $ar['class_id'],
            'class_name'       => $ar['class_name'],
            'subject'          => $ar['subject'],
            'is_assignment'    => true,
            'assignment_id'    => $ar['assignment_id'],
        ];
    }

    // Determine overall representative topic and quiz context for motivational AI coach
    $rep_score = 75.0;
    $weakest_topic_name = '';
    $weakest_subject = '';
    $weakest_quiz_title = '';
    $weakest_mod_title = '';
    $weakest_missing_concepts = '';

    if (!empty($weak_topics)) {
        $rep_score = $weak_topics[0]['score_pct'];
        $weakest_topic_name = $weak_topics[0]['topic'];
        $weakest_subject = $weak_topics[0]['subject'] ?? '';
        if (!empty($weak_topics[0]['quiz_context'][0])) {
            $weakest_quiz_title = $weak_topics[0]['quiz_context'][0]['quiz_title'];
        }
        if (!empty($weak_topics[0]['modules'][0])) {
            $weakest_mod_title = $weak_topics[0]['modules'][0]['title'];
        }
    } elseif (!empty($strong_topics)) {
        $rep_score = $strong_topics[0]['score_pct'];
        $weakest_topic_name = $strong_topics[0]['topic'];
        $weakest_subject = $strong_topics[0]['subject'] ?? '';
    }

    $motivational_quote = cenlearn_get_motivational_quote(
        $rep_score,
        $weakest_topic_name,
        $weakest_subject,
        $weakest_quiz_title,
        $weakest_mod_title,
        $weakest_missing_concepts
    );

    return [
        'has_data'            => true,
        'all_topics'          => $all_topics,
        'weak_topics'         => array_values($weak_topics),
        'strong_topics'       => array_values($strong_topics),
        'recommendations'     => $recommendations,
        'assignment_recs'     => $assignment_recs,
        'ml_active'           => true,
        'total_topics'        => count($all_topics),
        'weak_count'          => count($weak_topics),
        'strong_count'        => count($strong_topics),
        'motivational_quote'  => $motivational_quote,
        'overall_performance' => cenlearn_overall_student_performance($conn, $student_code),
    ];
}
?>
