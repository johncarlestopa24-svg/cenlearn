<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once __DIR__ . '/../includes/conn.php';
header('Content-Type: application/json');

if (empty($_SESSION['user'])) {
    echo json_encode(['success' => false, 'msg' => 'Not logged in']);
    exit;
}
$user = $_SESSION['user'];
$uc   = $conn->real_escape_string($user['user_code']);
$role = strtoupper($user['user_group']);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Helper: Call Python ML Microservice ──────────────────────────────────────
function callQuizMlApi($endpoint, $payload, $timeout = 10) {
    $url = "http://127.0.0.1:5000" . $endpoint;
    $ch = curl_init($url);
    $jsonData = json_encode($payload);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result && $httpCode >= 200 && $httpCode < 300) {
        $decoded = json_decode($result, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
}

// ── Helper: Semantic Essay Evaluator (Module & Concept Grounded) ─────────────
function evaluateEssaySemantic($studentAns, $moduleText, $questionText, $requiredConcepts = [], $rubric = [], $maxPts = 10) {
    $ans = trim((string)$studentAns);
    $maxPts = floatval($maxPts > 0 ? $maxPts : 10);
    if (strlen($ans) < 6) {
        return [
            'score'             => 0.0,
            'max_score'         => $maxPts,
            'pct'               => 0.0,
            'feedback'          => 'No answer provided or answer is too short.',
            'rubric_scores'     => [],
            'detected_concepts' => [],
            'missing_concepts'  => is_array($requiredConcepts) ? array_map(function($c){ return is_array($c) ? ($c['concept'] ?? '') : (string)$c; }, $requiredConcepts) : [],
            'confidence'        => 1.0
        ];
    }

    // Try Python ML API first
    $mlPayload = [
        'module_text'       => $moduleText,
        'student_answer'    => $ans,
        'essay_question'    => $questionText,
        'required_concepts' => $requiredConcepts,
        'rubric'            => $rubric,
        'maximum_score'     => $maxPts
    ];
    $mlRes = callQuizMlApi('/grade_essay', $mlPayload, 6);
    if ($mlRes && !empty($mlRes['success'])) {
        return [
            'score'             => floatval($mlRes['suggested_score'] ?? 0),
            'max_score'         => floatval($mlRes['max_score'] ?? $maxPts),
            'pct'               => floatval($mlRes['percentage'] ?? 0),
            'feedback'          => $mlRes['feedback'] ?? '',
            'rubric_scores'     => $mlRes['rubric_scores'] ?? [],
            'detected_concepts' => $mlRes['detected_concepts'] ?? [],
            'missing_concepts'  => $mlRes['missing_concepts'] ?? [],
            'confidence'        => floatval($mlRes['confidence'] ?? 0.85)
        ];
    }

    // High-Precision Local Semantic Fallback
    $stopwords = array_flip([
        'the','a','an','and','or','but','if','because','as','what','which','this','that','these','those',
        'then','just','so','than','such','both','through','about','for','is','of','while','during','to',
        'what','who','which','why','how','all','any','both','each','few','more','most','other','some',
        'no','nor','not','only','own','same','so','than','too','very','can','will','just','should','now',
        'explain','describe','discuss','sentence','sentences','paragraph','words','your','own','in','on',
        'at','by','from','up','down','into','over','after','before','under','again','where','when','there',
        'give','list','name','state','define','sample','rubric','teacher','grading','please','write'
    ]);

    $cleanTokens = function($t) use ($stopwords) {
        $clean = preg_replace('/[^a-zA-Z0-9\s]/u', ' ', strtolower($t));
        $words = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($words, function($w) use ($stopwords) {
            return strlen($w) >= 3 && !isset($stopwords[$w]);
        }));
    };

    $ansTokens = array_unique($cleanTokens($ans));
    $wordCount = count(preg_split('/\s+/u', $ans));

    $detected = [];
    $missing  = [];
    $conceptTotalEarned = 0.0;
    $conceptMaxTotal = 0.0;

    $cList = is_array($requiredConcepts) && !empty($requiredConcepts) ? $requiredConcepts : [
        ['concept' => 'Context and explanation of core principle', 'points' => $maxPts * 0.5],
        ['concept' => 'Practical application and significance', 'points' => $maxPts * 0.3],
        ['concept' => 'Clear organization and terminology', 'points' => $maxPts * 0.2]
    ];

    foreach ($cList as $cItem) {
        $cText = is_array($cItem) ? ($cItem['concept'] ?? '') : (string)$cItem;
        $cPts  = floatval(is_array($cItem) ? ($cItem['points'] ?? ($maxPts / count($cList))) : ($maxPts / count($cList)));
        $conceptMaxTotal += $cPts;

        $cTokens = array_unique($cleanTokens($cText));
        if (empty($cTokens)) {
            $overlap = 1.0;
        } else {
            $matchedCount = 0;
            foreach ($cTokens as $ct) {
                foreach ($ansTokens as $at) {
                    if ($ct === $at || strpos($at, $ct) !== false || strpos($ct, $at) !== false) {
                        $matchedCount++;
                        break;
                    }
                }
            }
            $overlap = $matchedCount / count($cTokens);
        }

        if ($overlap >= 0.40 || ($wordCount >= 25 && $overlap >= 0.20)) {
            $earned = round($cPts * min(1.0, $overlap * 1.25), 1);
            $detected[] = ['concept' => $cText, 'earned' => $earned, 'max' => $cPts];
            $conceptTotalEarned += $earned;
        } else {
            $missing[] = $cText;
        }
    }

    $understandingRatio = $conceptMaxTotal > 0 ? ($conceptTotalEarned / $conceptMaxTotal) : min(1.0, $wordCount / 35.0);
    $earnedScore = round($understandingRatio * $maxPts, 1);

    $rubricScores = [];
    if (is_array($rubric) && !empty($rubric)) {
        foreach ($rubric as $crit) {
            $cName = $crit['name'] ?? 'Criterion';
            $cMax  = floatval($crit['points'] ?? ($maxPts / count($rubric)));
            $rubricScores[$cName] = round($cMax * $understandingRatio, 1);
        }
    }

    $feedbackParts = [];
    if (!empty($detected)) {
        $feedbackParts[] = "Accurately demonstrated understanding of " . htmlspecialchars($detected[0]['concept']) . ".";
    }
    if (!empty($missing)) {
        $feedbackParts[] = "To improve, elaborate further on: " . htmlspecialchars($missing[0]) . ".";
    } else {
        $feedbackParts[] = "Comprehensive response covering key module concepts thoroughly.";
    }

    return [
        'score'             => $earnedScore,
        'max_score'         => $maxPts,
        'pct'               => round(($earnedScore / $maxPts) * 100, 1),
        'feedback'          => implode(' ', $feedbackParts),
        'rubric_scores'     => $rubricScores,
        'detected_concepts' => $detected,
        'missing_concepts'  => $missing,
        'confidence'        => 0.82
    ];
}

// ── Helper: Deterministic PRNG Shuffle ───────────────────────────────────────
function deterministicArrayShuffle(&$items, $seed) {
    if (!is_array($items) || count($items) <= 1) return;
    $count = count($items);
    // Linear Congruential Generator for reproducible shuffling
    $state = abs(intval($seed)) % 2147483647;
    if ($state === 0) $state = 123456789;

    for ($i = $count - 1; $i > 0; $i--) {
        $state = (1103515245 * $state + 12345) % 2147483647;
        $j = $state % ($i + 1);
        $temp = $items[$i];
        $items[$i] = $items[$j];
        $items[$j] = $temp;
    }
}

// ── Teacher: Create Quiz ────────────────────────────────────────────────────
if ($action === 'create' && $role === 'TEACHER') {
    $class_id         = intval($_POST['class_id'] ?? 0);
    $title            = trim($_POST['title'] ?? '');
    $instructions     = trim($_POST['instructions'] ?? '');
    $time_limit       = intval($_POST['time_limit'] ?? 0);
    $start_date       = trim($_POST['start_date'] ?? '');
    $due_date         = trim($_POST['due_date'] ?? '');
    $questions        = json_decode($_POST['questions'] ?? '[]', true) ?: [];
    $module_id        = intval($_POST['module_id'] ?? 0);
    $module_version   = trim($_POST['module_version'] ?? '1.0');
    $sq               = intval($_POST['shuffle_questions'] ?? 0);
    $sa               = intval($_POST['shuffle_answers']   ?? 0);
    $sm               = intval($_POST['shuffle_matching']  ?? 0);
    $stf              = intval($_POST['shuffle_tf']        ?? 0);
    $randStudent      = intval($_POST['randomize_student'] ?? 0);
    $grading_mode     = $conn->real_escape_string(trim($_POST['grading_mode'] ?? 'exact'));
    $ms_scoring_mode  = $conn->real_escape_string(trim($_POST['multi_select_scoring_mode'] ?? 'partial_credit'));
    $term             = $conn->real_escape_string(trim($_POST['term'] ?? 'midterm'));
    if (!in_array($term, ['midterm', 'final', 'none'])) $term = 'midterm';

    if (!$title) { echo json_encode(['success' => false, 'msg' => 'Quiz Title is required']); exit; }
    if (empty($questions)) { echo json_encode(['success' => false, 'msg' => 'Add at least one question']); exit; }

    if ($class_id > 0) {
        $chk = $conn->query("SELECT id FROM classes WHERE id=$class_id AND teacher_code='$uc'");
        if ($chk->num_rows === 0) { echo json_encode(['success' => false, 'msg' => 'Unauthorized class selected']); exit; }
    }

    // Auto-detect & track relevant learning module for this teacher using ML & keyword content similarity
    if ($module_id <= 0) {
        $qTextList = [];
        foreach ($questions as $q) {
            $qTextList[] = ($q['question_text'] ?? '') . ' ' . ($q['topic'] ?? '');
        }
        $quizContent = strtolower($title . ' ' . implode(' ', $qTextList));

        // Find teacher's uploaded modules
        $modSql = "SELECT m.id, m.title, m.topic, m.original_name, m.filename 
                   FROM class_modules m 
                   LEFT JOIN classes c ON m.class_id = c.id 
                   WHERE m.teacher_code = '$uc' OR c.teacher_code = '$uc'";
        if ($class_id > 0) {
            $modSql .= " OR m.class_id = $class_id";
        }
        $modSql .= " ORDER BY m.id DESC";
        $modRes = $conn->query($modSql);

        $bestModuleId = 0;
        $highestScore = 0;
        if ($modRes && $modRes->num_rows > 0) {
            while ($m = $modRes->fetch_assoc()) {
                $mId = intval($m['id']);
                $mTitle = strtolower($m['title'] ?? '');
                $mTopic = strtolower($m['topic'] ?? '');
                $mName = strtolower($m['original_name'] ?? '');

                $score = 0;
                if ($mTopic && stripos($quizContent, $mTopic) !== false) $score += 12;
                if ($mTitle && stripos($quizContent, $mTitle) !== false) $score += 10;

                $words = preg_split('/[\s,\.\-_]+/', $mTitle . ' ' . $mTopic . ' ' . $mName);
                foreach ($words as $w) {
                    $w = trim($w);
                    if (strlen($w) >= 4 && stripos($quizContent, $w) !== false) {
                        $score += 3;
                    }
                }

                if ($score > $highestScore) {
                    $highestScore = $score;
                    $bestModuleId = $mId;
                }
                if ($bestModuleId === 0) {
                    $bestModuleId = $mId;
                }
            }
        }
        if ($bestModuleId > 0) {
            $module_id = $bestModuleId;
        }
    }

    $t   = $conn->real_escape_string($title);
    $ins = $conn->real_escape_string($instructions);
    $tl  = $time_limit > 0 ? $time_limit : 'NULL';
    $sd  = $start_date ? "'" . $conn->real_escape_string($start_date) . "'" : 'NULL';
    $dd  = $due_date ? "'" . $conn->real_escape_string($due_date) . "'" : 'NULL';
    $cidVal = $class_id > 0 ? $class_id : 0;
    $modIdVal = $module_id > 0 ? $module_id : 'NULL';
    $modVerVal = $conn->real_escape_string($module_version);

    $insRes = $conn->query("INSERT INTO quizzes (
        class_id, teacher_code, title, instructions, time_limit, start_date, due_date,
        shuffle_questions, shuffle_answers, shuffle_matching, shuffle_tf, randomize_student,
        module_id, module_version, grading_mode, multi_select_scoring_mode, term, is_active
    ) VALUES (
        $cidVal, '$uc', '$t', '$ins', $tl, $sd, $dd,
        $sq, $sa, $sm, $stf, $randStudent,
        $modIdVal, '$modVerVal', '$grading_mode', '$ms_scoring_mode', '$term', 1
    )");

    if (!$insRes) {
        echo json_encode(['success' => false, 'msg' => 'Failed to save quiz: ' . $conn->error]);
        exit;
    }
    $quiz_id = $conn->insert_id;

    // Calculate total points & insert each question
    $quiz_max_score = 0;
    $qIndex = 1;
    foreach ($questions as $qitem) {
        $pts = floatval($qitem['points'] ?? 1);
        if ($pts <= 0) $pts = 1;
        $quiz_max_score += $pts;

        $qType = strtolower(trim($qitem['question_type'] ?? 'multiple_choice'));
        if ($qType === 'single_mcq') $qType = 'multiple_choice';
        if ($qType === 'multiple_answers') $qType = 'multi_select';

        $qUid = trim($qitem['question_uid'] ?? '');
        if (!$qUid) {
            $prefixMap = [
                'multiple_choice'     => 'MCQ',
                'multi_select'        => 'MSQ',
                'true_false'          => 'TF',
                'modified_true_false' => 'MTF',
                'identification'      => 'ID',
                'enumeration'         => 'ENUM',
                'matching'            => 'MATCH',
                'essay'               => 'ESSAY'
            ];
            $prefix = $prefixMap[$qType] ?? 'Q';
            $qUid = sprintf("%s-%03d", $prefix, $qIndex);
        }
        $qIndex++;

        // Process options data & permanent Option IDs
        $optsData = $qitem['options_data'] ?? [];
        if (empty($optsData) && !empty($qitem['options']) && is_array($qitem['options'])) {
            $optsData = [];
            foreach ($qitem['options'] as $idx => $optText) {
                $optsData[] = [
                    'id'   => sprintf("opt-%s-%02d", strtolower($qUid), $idx + 1),
                    'text' => trim((string)$optText)
                ];
            }
        }

        // Process correct option IDs
        $correctOptionIds = $qitem['correct_option_ids'] ?? [];
        if (empty($correctOptionIds) && !empty($qitem['correct_answer']) && !empty($optsData)) {
            $cRaw = trim((string)$qitem['correct_answer']);
            $ansParts = preg_split('/[,;&]|\band\b/i', $cRaw);
            $ansParts = array_filter(array_map('trim', $ansParts));
            foreach ($optsData as $oIdx => $od) {
                $letter = chr(65 + $oIdx);
                $odText = trim($od['text']);
                foreach ($ansParts as $ap) {
                    if (strcasecmp($odText, $ap) === 0 || strcasecmp($od['id'], $ap) === 0 || strcasecmp($letter, $ap) === 0) {
                        $correctOptionIds[] = $od['id'];
                        break;
                    }
                }
            }
        }
        if ($qType === 'multiple_choice' && count($correctOptionIds) > 1) {
            $qType = 'multi_select';
        } elseif ($qType === 'multi_select' && count($correctOptionIds) === 1 && !empty($optsData)) {
            $qType = 'multiple_choice';
        }

        $qt            = $conn->real_escape_string(trim($qitem['question_text'] ?? ''));
        $qtopic        = $conn->real_escape_string(trim($qitem['topic'] ?? 'General'));
        $qtypeVal      = $conn->real_escape_string($qType);
        $quidVal       = $conn->real_escape_string($qUid);
        $optsDataJson  = $conn->real_escape_string(json_encode($optsData));
        $correctIdsJson= $conn->real_escape_string(json_encode($correctOptionIds));
        $acceptableJson= $conn->real_escape_string(json_encode($qitem['acceptable_answers'] ?? []));
        $matchingJson  = $conn->real_escape_string(json_encode($qitem['matching_pairs'] ?? []));
        $rubricJson    = $conn->real_escape_string(json_encode($qitem['rubric_json'] ?? []));
        $reqConceptsJson=$conn->real_escape_string(json_encode($qitem['required_concepts'] ?? []));
        $legacyOpts    = $conn->real_escape_string(json_encode(array_column($optsData, 'text') ?: ($qitem['options'] ?? [])));
        $legacyCorrect = $conn->real_escape_string(trim((string)($qitem['correct_answer'] ?? '')));
        $truthVal      = isset($qitem['truth_value']) ? (intval($qitem['truth_value']) ? '1' : '0') : 'NULL';
        $incPhrase     = $conn->real_escape_string(trim($qitem['incorrect_phrase'] ?? ''));
        $corrRep       = $conn->real_escape_string(trim($qitem['correct_replacement'] ?? ''));
        $explanation   = $conn->real_escape_string(trim($qitem['explanation'] ?? ''));

        $conn->query("INSERT INTO quiz_questions (
            quiz_id, question_uid, question_text, topic, question_type,
            options_data, correct_option_ids, acceptable_answers, matching_pairs,
            rubric_json, required_concepts, truth_value, incorrect_phrase, correct_replacement,
            options, correct_answer, points, module_id, module_version, explanation
        ) VALUES (
            $quiz_id, '$quidVal', '$qt', '$qtopic', '$qtypeVal',
            '$optsDataJson', '$correctIdsJson', '$acceptableJson', '$matchingJson',
            '$rubricJson', '$reqConceptsJson', $truthVal, '$incPhrase', '$corrRep',
            '$legacyOpts', '$legacyCorrect', $pts, $modIdVal, '$modVerVal', '$explanation'
        )");
    }

    // Auto-create class_record_columns row
    if ($term !== 'none' && $class_id > 0) {
        $qColTitle = $conn->real_escape_string($title);
        $colCheck = $conn->query("SELECT id FROM class_record_columns WHERE class_id=$class_id AND quiz_id=$quiz_id LIMIT 1");
        if (!$colCheck || $colCheck->num_rows === 0) {
            $conn->query("INSERT INTO class_record_columns (class_id, component, title, max_score, sort_order, term, quiz_id)
                          VALUES ($class_id, 'written', '$qColTitle', $quiz_max_score, 0, '$term', $quiz_id)");
        }
    }

    echo json_encode(['success' => true, 'msg' => 'Quiz created successfully!', 'id' => $quiz_id]);
    exit;
}

// ── Teacher: AI Generate Quiz from Module ───────────────────────────────────
if ($action === 'generate_quiz_from_module' && $role === 'TEACHER') {
    $class_id        = intval($_POST['class_id'] ?? 0);
    $module_id       = intval($_POST['module_id'] ?? 0);
    $module_version  = trim($_POST['module_version'] ?? '1.0');
    $requested_types = json_decode($_POST['requested_types'] ?? '[]', true) ?: [
        "multiple_choice", "multi_select", "true_false", "modified_true_false",
        "identification", "enumeration", "matching", "essay"
    ];
    $question_counts = json_decode($_POST['question_counts'] ?? '{}', true) ?: [];
    $difficulty      = trim($_POST['difficulty'] ?? 'medium');

    $moduleText = "";
    if ($module_id > 0) {
        $anQ = $conn->query("SELECT extracted_text, title, filename FROM class_material_analysis WHERE module_id=$module_id LIMIT 1");
        if ($anQ && $anQ->num_rows > 0) {
            $anRow = $anQ->fetch_assoc();
            $moduleText = $anRow['extracted_text'] ?? '';
        }
        if (empty($moduleText)) {
            $mQ = $conn->query("SELECT title, description, filename FROM class_modules WHERE id=$module_id LIMIT 1");
            if ($mQ && $mQ->num_rows > 0) {
                $mRow = $mQ->fetch_assoc();
                $filePath = __DIR__ . '/../uploads/modules/' . $mRow['filename'];
                if (file_exists($filePath)) {
                    $raw = file_get_contents($filePath);
                    preg_match_all('/[a-zA-Z0-9\+\-\*\/\=\%\,\.\:\;\(\)\s]{4,}/', $raw, $matches);
                    $moduleText = implode(' ', $matches[0] ?? []);
                }
                if (empty($moduleText)) {
                    $moduleText = ($mRow['title'] ?? '') . ' ' . ($mRow['description'] ?? '');
                }
            }
        }
    }

    if (empty($moduleText) && !empty($_POST['custom_module_text'])) {
        $moduleText = trim($_POST['custom_module_text']);
    }

    if (strlen(trim($moduleText)) < 30) {
        echo json_encode(['success' => false, 'msg' => 'Selected module has no readable text content for quiz generation.']);
        exit;
    }

    $mlPayload = [
        'module_text'     => $moduleText,
        'module_id'       => $module_id,
        'module_version'  => $module_version,
        'class_id'        => $class_id,
        'requested_types' => $requested_types,
        'question_counts' => $question_counts,
        'difficulty'      => $difficulty
    ];

    $mlRes = callQuizMlApi('/generate_quiz', $mlPayload, 15);
    if ($mlRes && !empty($mlRes['success'])) {
        echo json_encode($mlRes);
        exit;
    }

    echo json_encode(['success' => false, 'msg' => 'AI Service was unable to generate questions from this module.']);
    exit;
}

// ── Teacher: Add Class / Assign Quiz to Target Class (Copy) ─────────────────
if (($action === 'copy' || $action === 'assign_to_class') && in_array($role, ['TEACHER', 'ADMIN', 'SUPERADMIN'])) {
    $quiz_id         = intval($_POST['quiz_id'] ?? 0);
    $target_class_id = intval($_POST['target_class_id'] ?? 0);
    $start_date      = trim($_POST['start_date'] ?? '');
    $due_date        = trim($_POST['due_date'] ?? '');

    if (!$quiz_id || !$target_class_id) {
        echo json_encode(['success' => false, 'msg' => 'Source Quiz and Target Class are required.']);
        exit;
    }

    // Verify source quiz
    $qzQ = $conn->query("SELECT * FROM quizzes WHERE id=$quiz_id");
    if (!$qzQ || $qzQ->num_rows === 0) {
        echo json_encode(['success' => false, 'msg' => 'Source quiz not found.']);
        exit;
    }
    $srcQuiz = $qzQ->fetch_assoc();

    // Verify target class
    $clsQ = $conn->query("SELECT id, class_name FROM classes WHERE id=$target_class_id");
    if (!$clsQ || $clsQ->num_rows === 0) {
        echo json_encode(['success' => false, 'msg' => 'Target class not found.']);
        exit;
    }
    $targetClass = $clsQ->fetch_assoc();

    // Check if target class already has an active quiz with this title
    $titleEsc = $conn->real_escape_string($srcQuiz['title']);
    $dupCheck = $conn->query("SELECT id FROM quizzes WHERE class_id=$target_class_id AND title='$titleEsc' AND is_active=1 LIMIT 1");
    if ($dupCheck && $dupCheck->num_rows > 0) {
        echo json_encode(['success' => false, 'msg' => "Quiz is already assigned to '{$targetClass['class_name']}'."]);
        exit;
    }

    $t   = $conn->real_escape_string($srcQuiz['title']);
    $ins = $conn->real_escape_string($srcQuiz['instructions'] ?? '');
    $tl  = intval($srcQuiz['time_limit'] ?? 0) > 0 ? intval($srcQuiz['time_limit']) : 'NULL';
    $sd  = $start_date ? "'" . $conn->real_escape_string($start_date) . "'" : ($srcQuiz['start_date'] ? "'" . $conn->real_escape_string($srcQuiz['start_date']) . "'" : 'NULL');
    $dd  = $due_date ? "'" . $conn->real_escape_string($due_date) . "'" : ($srcQuiz['due_date'] ? "'" . $conn->real_escape_string($srcQuiz['due_date']) . "'" : 'NULL');
    $modIdVal = !empty($srcQuiz['module_id']) ? intval($srcQuiz['module_id']) : 'NULL';
    $modVerVal = $conn->real_escape_string($srcQuiz['module_version'] ?? '1.0');
    $sq = intval($srcQuiz['shuffle_questions'] ?? 0);
    $sa = intval($srcQuiz['shuffle_answers'] ?? 0);
    $sm = intval($srcQuiz['shuffle_matching'] ?? 0);
    $stf = intval($srcQuiz['shuffle_tf'] ?? 0);
    $randStudent = intval($srcQuiz['randomize_student'] ?? 0);
    $grading_mode = $conn->real_escape_string($srcQuiz['grading_mode'] ?? 'exact');
    $ms_scoring_mode = $conn->real_escape_string($srcQuiz['multi_select_scoring_mode'] ?? 'partial_credit');
    $term = $conn->real_escape_string($srcQuiz['term'] ?? 'midterm');

    $insRes = $conn->query("INSERT INTO quizzes (
        class_id, teacher_code, title, instructions, time_limit, start_date, due_date,
        shuffle_questions, shuffle_answers, shuffle_matching, shuffle_tf, randomize_student,
        module_id, module_version, grading_mode, multi_select_scoring_mode, term, is_active
    ) VALUES (
        $target_class_id, '$uc', '$t', '$ins', $tl, $sd, $dd,
        $sq, $sa, $sm, $stf, $randStudent,
        $modIdVal, '$modVerVal', '$grading_mode', '$ms_scoring_mode', '$term', 1
    )");

    if (!$insRes) {
        echo json_encode(['success' => false, 'msg' => 'Failed to create quiz for class: ' . $conn->error]);
        exit;
    }
    $new_quiz_id = $conn->insert_id;

    // Copy all questions from source quiz to new quiz
    $srcQuestionsQ = $conn->query("SELECT * FROM quiz_questions WHERE quiz_id=$quiz_id ORDER BY id ASC");
    $copiedQCount = 0;
    if ($srcQuestionsQ) {
        while ($qRow = $srcQuestionsQ->fetch_assoc()) {
            $quid = $conn->real_escape_string($qRow['question_uid']);
            $qt = $conn->real_escape_string($qRow['question_text']);
            $qtopic = $conn->real_escape_string($qRow['topic'] ?? 'General');
            $qtype = $conn->real_escape_string($qRow['question_type']);
            $optsData = $conn->real_escape_string($qRow['options_data'] ?? '[]');
            $corrIds = $conn->real_escape_string($qRow['correct_option_ids'] ?? '[]');
            $accept = $conn->real_escape_string($qRow['acceptable_answers'] ?? '[]');
            $matchPairs = $conn->real_escape_string($qRow['matching_pairs'] ?? '[]');
            $rubric = $conn->real_escape_string($qRow['rubric_json'] ?? '[]');
            $reqConc = $conn->real_escape_string($qRow['required_concepts'] ?? '[]');
            $truthVal = isset($qRow['truth_value']) && $qRow['truth_value'] !== null ? ($qRow['truth_value'] ? '1' : '0') : 'NULL';
            $incPhr = $conn->real_escape_string($qRow['incorrect_phrase'] ?? '');
            $corrRep = $conn->real_escape_string($qRow['correct_replacement'] ?? '');
            $legacyOpts = $conn->real_escape_string($qRow['options'] ?? '[]');
            $legacyCorr = $conn->real_escape_string($qRow['correct_answer'] ?? '');
            $pts = floatval($qRow['points'] ?? 1);
            $qModId = !empty($qRow['module_id']) ? intval($qRow['module_id']) : 'NULL';
            $qModVer = $conn->real_escape_string($qRow['module_version'] ?? '1.0');
            $expl = $conn->real_escape_string($qRow['explanation'] ?? '');

            $conn->query("INSERT INTO quiz_questions (
                quiz_id, question_uid, question_text, topic, question_type,
                options_data, correct_option_ids, acceptable_answers, matching_pairs,
                rubric_json, required_concepts, truth_value, incorrect_phrase, correct_replacement,
                options, correct_answer, points, module_id, module_version, explanation
            ) VALUES (
                $new_quiz_id, '$quid', '$qt', '$qtopic', '$qtype',
                '$optsData', '$corrIds', '$accept', '$matchPairs',
                '$rubric', '$reqConc', $truthVal, '$incPhr', '$corrRep',
                '$legacyOpts', '$legacyCorr', $pts, $qModId, '$qModVer', '$expl'
            )");
            $copiedQCount++;
        }
    }

    // Auto-create class_record_columns row for gradebook
    if ($term !== 'none' && $target_class_id > 0) {
        $qColTitle = $conn->real_escape_string($srcQuiz['title']);
        $colCheck = $conn->query("SELECT id FROM class_record_columns WHERE class_id=$target_class_id AND quiz_id=$new_quiz_id LIMIT 1");
        if (!$colCheck || $colCheck->num_rows === 0) {
            $totPts = 0;
            $ptQ = $conn->query("SELECT SUM(points) AS tp FROM quiz_questions WHERE quiz_id=$new_quiz_id");
            if ($ptQ && $ptRow = $ptQ->fetch_assoc()) $totPts = floatval($ptRow['tp'] ?? 0);
            if ($totPts <= 0) $totPts = 100;
            $conn->query("INSERT INTO class_record_columns (class_id, component, title, max_score, sort_order, term, quiz_id)
                          VALUES ($target_class_id, 'QUIZ', '$qColTitle', $totPts, 99, '$term', $new_quiz_id)");
        }
    }

    echo json_encode([
        'success' => true,
        'msg' => "Quiz successfully added and assigned to '{$targetClass['class_name']}'!",
        'new_quiz_id' => $new_quiz_id
    ]);
    exit;
}

// ── Teacher: Duplicate Entire Quiz ──────────────────────────────────────────
if ($action === 'duplicate_quiz' && in_array($role, ['TEACHER', 'ADMIN', 'SUPERADMIN'])) {
    $quiz_id         = intval($_POST['quiz_id'] ?? 0);
    $target_class_id = intval($_POST['target_class_id'] ?? 0);

    if (!$quiz_id) {
        echo json_encode(['success' => false, 'msg' => 'Quiz ID is required.']);
        exit;
    }

    $qzQ = $conn->query("SELECT * FROM quizzes WHERE id=$quiz_id");
    if (!$qzQ || $qzQ->num_rows === 0) {
        echo json_encode(['success' => false, 'msg' => 'Quiz not found.']);
        exit;
    }
    $srcQuiz = $qzQ->fetch_assoc();

    $classId = $target_class_id > 0 ? $target_class_id : intval($srcQuiz['class_id']);
    
    // If target class is the same class, append (Copy) to title
    $titleStr = $srcQuiz['title'];
    if ($classId === intval($srcQuiz['class_id']) || $target_class_id <= 0) {
        $titleStr .= ' (Copy)';
    }
    $t   = $conn->real_escape_string($titleStr);
    $ins = $conn->real_escape_string($srcQuiz['instructions'] ?? '');
    $tl  = intval($srcQuiz['time_limit'] ?? 0) > 0 ? intval($srcQuiz['time_limit']) : 'NULL';
    $sd  = $srcQuiz['start_date'] ? "'" . $conn->real_escape_string($srcQuiz['start_date']) . "'" : 'NULL';
    $dd  = $srcQuiz['due_date'] ? "'" . $conn->real_escape_string($srcQuiz['due_date']) . "'" : 'NULL';
    $modIdVal = !empty($srcQuiz['module_id']) ? intval($srcQuiz['module_id']) : 'NULL';
    $modVerVal = $conn->real_escape_string($srcQuiz['module_version'] ?? '1.0');
    $sq = intval($srcQuiz['shuffle_questions'] ?? 0);
    $sa = intval($srcQuiz['shuffle_answers'] ?? 0);
    $sm = intval($srcQuiz['shuffle_matching'] ?? 0);
    $stf = intval($srcQuiz['shuffle_tf'] ?? 0);
    $randStudent = intval($srcQuiz['randomize_student'] ?? 0);
    $grading_mode = $conn->real_escape_string($srcQuiz['grading_mode'] ?? 'exact');
    $ms_scoring_mode = $conn->real_escape_string($srcQuiz['multi_select_scoring_mode'] ?? 'partial_credit');
    $term = $conn->real_escape_string($srcQuiz['term'] ?? 'midterm');

    $insRes = $conn->query("INSERT INTO quizzes (
        class_id, teacher_code, title, instructions, time_limit, start_date, due_date,
        shuffle_questions, shuffle_answers, shuffle_matching, shuffle_tf, randomize_student,
        module_id, module_version, grading_mode, multi_select_scoring_mode, term, is_active
    ) VALUES (
        $classId, '$uc', '$t', '$ins', $tl, $sd, $dd,
        $sq, $sa, $sm, $stf, $randStudent,
        $modIdVal, '$modVerVal', '$grading_mode', '$ms_scoring_mode', '$term', 1
    )");

    if (!$insRes) {
        echo json_encode(['success' => false, 'msg' => 'Failed to duplicate quiz: ' . $conn->error]);
        exit;
    }
    $new_quiz_id = $conn->insert_id;

    // Copy all questions
    $srcQuestionsQ = $conn->query("SELECT * FROM quiz_questions WHERE quiz_id=$quiz_id ORDER BY id ASC");
    $copiedCount = 0;
    if ($srcQuestionsQ) {
        while ($qRow = $srcQuestionsQ->fetch_assoc()) {
            $quid = $conn->real_escape_string($qRow['question_uid']);
            $qt = $conn->real_escape_string($qRow['question_text']);
            $qtopic = $conn->real_escape_string($qRow['topic'] ?? 'General');
            $qtype = $conn->real_escape_string($qRow['question_type']);
            $optsData = $conn->real_escape_string($qRow['options_data'] ?? '[]');
            $corrIds = $conn->real_escape_string($qRow['correct_option_ids'] ?? '[]');
            $accept = $conn->real_escape_string($qRow['acceptable_answers'] ?? '[]');
            $matchPairs = $conn->real_escape_string($qRow['matching_pairs'] ?? '[]');
            $rubric = $conn->real_escape_string($qRow['rubric_json'] ?? '[]');
            $reqConc = $conn->real_escape_string($qRow['required_concepts'] ?? '[]');
            $truthVal = isset($qRow['truth_value']) && $qRow['truth_value'] !== null ? ($qRow['truth_value'] ? '1' : '0') : 'NULL';
            $incPhr = $conn->real_escape_string($qRow['incorrect_phrase'] ?? '');
            $corrRep = $conn->real_escape_string($qRow['correct_replacement'] ?? '');
            $legacyOpts = $conn->real_escape_string($qRow['options'] ?? '[]');
            $legacyCorr = $conn->real_escape_string($qRow['correct_answer'] ?? '');
            $pts = floatval($qRow['points'] ?? 1);
            $qModId = !empty($qRow['module_id']) ? intval($qRow['module_id']) : 'NULL';
            $qModVer = $conn->real_escape_string($qRow['module_version'] ?? '1.0');
            $expl = $conn->real_escape_string($qRow['explanation'] ?? '');

            $conn->query("INSERT INTO quiz_questions (
                quiz_id, question_uid, question_text, topic, question_type,
                options_data, correct_option_ids, acceptable_answers, matching_pairs,
                rubric_json, required_concepts, truth_value, incorrect_phrase, correct_replacement,
                options, correct_answer, points, module_id, module_version, explanation
            ) VALUES (
                $new_quiz_id, '$quid', '$qt', '$qtopic', '$qtype',
                '$optsData', '$corrIds', '$accept', '$matchPairs',
                '$rubric', '$reqConc', $truthVal, '$incPhr', '$corrRep',
                '$legacyOpts', '$legacyCorr', $pts, $qModId, '$qModVer', '$expl'
            )");
            $copiedCount++;
        }
    }

    echo json_encode([
        'success' => true,
        'msg' => "Quiz duplicated successfully! ($copiedCount questions copied)",
        'new_quiz_id' => $new_quiz_id
    ]);
    exit;
}

// ── Teacher: Duplicate Individual Question ──────────────────────────────────
if ($action === 'duplicate_question' && in_array($role, ['TEACHER', 'ADMIN', 'SUPERADMIN'])) {
    $quiz_id     = intval($_POST['quiz_id'] ?? 0);
    $question_id = intval($_POST['question_id'] ?? 0);

    if (!$quiz_id || !$question_id) {
        echo json_encode(['success' => false, 'msg' => 'Quiz ID and Question ID are required.']);
        exit;
    }

    $qRowQ = $conn->query("SELECT * FROM quiz_questions WHERE id=$question_id AND quiz_id=$quiz_id");
    if (!$qRowQ || $qRowQ->num_rows === 0) {
        $qRowQ = $conn->query("SELECT * FROM quiz_questions WHERE id=$question_id");
        if (!$qRowQ || $qRowQ->num_rows === 0) {
            echo json_encode(['success' => false, 'msg' => 'Question not found.']);
            exit;
        }
    }
    $qRow = $qRowQ->fetch_assoc();

    $quid = $conn->real_escape_string($qRow['question_uid'] . '-COPY');
    $qt = $conn->real_escape_string($qRow['question_text'] . ' (Copy)');
    $qtopic = $conn->real_escape_string($qRow['topic'] ?? 'General');
    $qtype = $conn->real_escape_string($qRow['question_type']);
    $optsData = $conn->real_escape_string($qRow['options_data'] ?? '[]');
    $corrIds = $conn->real_escape_string($qRow['correct_option_ids'] ?? '[]');
    $accept = $conn->real_escape_string($qRow['acceptable_answers'] ?? '[]');
    $matchPairs = $conn->real_escape_string($qRow['matching_pairs'] ?? '[]');
    $rubric = $conn->real_escape_string($qRow['rubric_json'] ?? '[]');
    $reqConc = $conn->real_escape_string($qRow['required_concepts'] ?? '[]');
    $truthVal = isset($qRow['truth_value']) && $qRow['truth_value'] !== null ? ($qRow['truth_value'] ? '1' : '0') : 'NULL';
    $incPhr = $conn->real_escape_string($qRow['incorrect_phrase'] ?? '');
    $corrRep = $conn->real_escape_string($qRow['correct_replacement'] ?? '');
    $legacyOpts = $conn->real_escape_string($qRow['options'] ?? '[]');
    $legacyCorr = $conn->real_escape_string($qRow['correct_answer'] ?? '');
    $pts = floatval($qRow['points'] ?? 1);
    $qModId = !empty($qRow['module_id']) ? intval($qRow['module_id']) : 'NULL';
    $qModVer = $conn->real_escape_string($qRow['module_version'] ?? '1.0');
    $expl = $conn->real_escape_string($qRow['explanation'] ?? '');

    $insRes = $conn->query("INSERT INTO quiz_questions (
        quiz_id, question_uid, question_text, topic, question_type,
        options_data, correct_option_ids, acceptable_answers, matching_pairs,
        rubric_json, required_concepts, truth_value, incorrect_phrase, correct_replacement,
        options, correct_answer, points, module_id, module_version, explanation
    ) VALUES (
        $quiz_id, '$quid', '$qt', '$qtopic', '$qtype',
        '$optsData', '$corrIds', '$accept', '$matchPairs',
        '$rubric', '$reqConc', $truthVal, '$incPhr', '$corrRep',
        '$legacyOpts', '$legacyCorr', $pts, $qModId, '$qModVer', '$expl'
    )");

    if (!$insRes) {
        echo json_encode(['success' => false, 'msg' => 'Failed to duplicate question: ' . $conn->error]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'msg' => 'Question duplicated successfully!',
        'new_question_id' => $conn->insert_id
    ]);
    exit;
}


// ── Teacher: Regrade Module Essays ──────────────────────────────────────────
if ($action === 'regrade_module_essays' && $role === 'TEACHER') {
    $quiz_id = intval($_POST['quiz_id'] ?? 0);
    $qzQ = $conn->query("SELECT q.*, m.filename FROM quizzes q LEFT JOIN class_modules m ON q.module_id = m.id WHERE q.id=$quiz_id AND q.teacher_code='$uc'");
    if (!$qzQ || $qzQ->num_rows === 0) {
        echo json_encode(['success' => false, 'msg' => 'Quiz not found or unauthorized']);
        exit;
    }
    $quiz = $qzQ->fetch_assoc();
    $modText = "";
    if (!empty($quiz['module_id'])) {
        $maQ = $conn->query("SELECT extracted_text FROM class_material_analysis WHERE module_id=".intval($quiz['module_id']));
        if ($maQ && $maQ->num_rows > 0) $modText = $maQ->fetch_assoc()['extracted_text'] ?? '';
    }

    // Regrade all essay submissions for this quiz
    $qsQ = $conn->query("SELECT * FROM quiz_questions WHERE quiz_id=$quiz_id AND question_type='essay'");
    $essayQuestions = [];
    while ($qr = $qsQ->fetch_assoc()) $essayQuestions[$qr['id']] = $qr;

    $subsQ = $conn->query("SELECT * FROM quiz_submissions WHERE quiz_id=$quiz_id");
    $regradedCount = 0;
    while ($sub = $subsQ->fetch_assoc()) {
        $sCode = $sub['student_code'];
        $answers = json_decode($sub['answers'] ?? '{}', true) ?: [];
        $aiScores = json_decode($sub['ai_suggested_scores'] ?? '{}', true) ?: [];
        $rubricScoresMap = json_decode($sub['rubric_scores'] ?? '{}', true) ?: [];
        $feedbacksMap = json_decode($sub['essay_feedback'] ?? '{}', true) ?: [];

        foreach ($essayQuestions as $qId => $qRow) {
            $studentAns = $answers[$qId] ?? '';
            $reqC = json_decode($qRow['required_concepts'] ?? '[]', true) ?: [];
            $rub = json_decode($qRow['rubric_json'] ?? '[]', true) ?: [];
            $pts = floatval($qRow['points'] ?? 10);

            $eval = evaluateEssaySemantic($studentAns, $modText, $qRow['question_text'], $reqC, $rub, $pts);
            $aiScores[$qId] = $eval['score'];
            $rubricScoresMap[$qId] = $eval['rubric_scores'];
            $feedbacksMap[$qId] = $eval['feedback'];
        }

        $aiJson = $conn->real_escape_string(json_encode($aiScores));
        $rubJson = $conn->real_escape_string(json_encode($rubricScoresMap));
        $fbJson = $conn->real_escape_string(json_encode($feedbacksMap));
        $conn->query("UPDATE quiz_submissions SET
            ai_suggested_scores='$aiJson', rubric_scores='$rubJson', essay_feedback='$fbJson', module_version_used='{$quiz['module_version']}'
            WHERE quiz_id=$quiz_id AND student_code='$sCode'");
        $regradedCount++;
    }

    echo json_encode(['success' => true, 'msg' => "Successfully updated AI essay evaluations for $regradedCount student submissions."]);
    exit;
}

// ── Teacher: Override Essay Grade & Rubric ──────────────────────────────────
if ($action === 'override_essay_grade' && $role === 'TEACHER') {
    $quiz_id      = intval($_POST['quiz_id'] ?? 0);
    $student_code = $conn->real_escape_string(trim($_POST['student_code'] ?? ''));
    $question_id  = intval($_POST['question_id'] ?? 0);
    $teacher_score= floatval($_POST['teacher_score'] ?? 0);
    $feedback     = trim($_POST['teacher_feedback'] ?? '');
    $rubric_scores= json_decode($_POST['rubric_scores'] ?? '{}', true) ?: [];

    if (!$quiz_id || !$student_code || !$question_id) {
        echo json_encode(['success' => false, 'msg' => 'Missing required parameters']);
        exit;
    }

    $subQ = $conn->query("SELECT * FROM quiz_submissions WHERE quiz_id=$quiz_id AND student_code='$student_code' LIMIT 1");
    if (!$subQ || $subQ->num_rows === 0) {
        echo json_encode(['success' => false, 'msg' => 'Submission not found']);
        exit;
    }
    $sub = $subQ->fetch_assoc();

    $overrides = json_decode($sub['teacher_overrides'] ?? '{}', true) ?: [];
    $feedbacks = json_decode($sub['teacher_feedback'] ?? '{}', true) ?: [];
    $allRubric = json_decode($sub['rubric_scores'] ?? '{}', true) ?: [];

    $overrides[$question_id] = $teacher_score;
    if ($feedback) $feedbacks[$question_id] = $feedback;
    if (!empty($rubric_scores)) $allRubric[$question_id] = $rubric_scores;

    // Recalculate total submission score with teacher override taking absolute precedence
    $qsQ = $conn->query("SELECT id, points, question_type FROM quiz_questions WHERE quiz_id=$quiz_id");
    $aiScores = json_decode($sub['ai_suggested_scores'] ?? '{}', true) ?: (json_decode($sub['essay_scores'] ?? '{}', true) ?: []);
    $answers  = json_decode($sub['answers'] ?? '{}', true) ?: [];
    $totalScore = 0.0;

    while ($qr = $qsQ->fetch_assoc()) {
        $qid = intval($qr['id']);
        $type = $qr['question_type'];
        if ($type === 'essay') {
            if (isset($overrides[$qid])) {
                $totalScore += floatval($overrides[$qid]);
            } elseif (isset($aiScores[$qid])) {
                $totalScore += floatval($aiScores[$qid]);
            }
        } else {
            // Objective question score from submission
            // Check if student answer earned points
        }
    }

    $ovJson = $conn->real_escape_string(json_encode($overrides));
    $tfJson = $conn->real_escape_string(json_encode($feedbacks));
    $rbJson = $conn->real_escape_string(json_encode($allRubric));

    $conn->query("UPDATE quiz_submissions SET
        teacher_overrides='$ovJson', teacher_feedback='$tfJson', rubric_scores='$rbJson'
        WHERE quiz_id=$quiz_id AND student_code='$student_code'");

    echo json_encode([
        'success' => true,
        'msg' => 'Teacher grade override saved successfully!',
        'teacher_score' => $teacher_score
    ]);
    exit;
}

// ── Student / Teacher: Get Questions (Seed-Based Stable Presentation) ───────
if ($action === 'get_questions') {
    $id = intval($_POST['quiz_id'] ?? 0);
    $qz = $conn->query("SELECT q.*, c.id AS cid FROM quizzes q LEFT JOIN classes c ON q.class_id=c.id WHERE q.id=$id");
    if (!$qz || $qz->num_rows === 0) { echo json_encode(['success' => false, 'msg' => 'Quiz not available']); exit; }
    $quiz = $qz->fetch_assoc();

    $isTeacherOrAdmin = in_array($role, ['TEACHER', 'ADMIN', 'SUPERADMIN']) || strcasecmp($quiz['teacher_code'] ?? '', $uc) === 0;

    if (!$isTeacherOrAdmin) {
        if (empty($quiz['is_active'])) { echo json_encode(['success' => false, 'msg' => 'Quiz is currently inactive']); exit; }
        if (!empty($quiz['start_date']) && strtotime($quiz['start_date']) > time()) {
            echo json_encode(['success' => false, 'msg' => 'This quiz is scheduled to start on ' . date('M d, Y g:i A', strtotime($quiz['start_date']))]);
            exit;
        }
        if (!empty($quiz['due_date']) && strtotime($quiz['due_date']) < time()) {
            echo json_encode(['success' => false, 'msg' => 'This quiz has expired.']);
            exit;
        }
        $done = $conn->query("
            SELECT s.score, s.total_points 
            FROM quiz_submissions s 
            JOIN quizzes q2 ON s.quiz_id = q2.id 
            WHERE s.student_code='$uc' 
              AND (s.quiz_id=$id OR (q2.title='".addslashes($quiz['title'])."' AND q2.teacher_code='".addslashes($quiz['teacher_code'])."' AND q2.term='".addslashes($quiz['term'])."'))
            LIMIT 1
        ");
        if ($done && $done->num_rows > 0) {
            $sub = $done->fetch_assoc();
            echo json_encode([
                'success' => true,
                'already_submitted' => true,
                'msg' => 'Already submitted',
                'score' => floatval($sub['score']),
                'total' => intval($sub['total_points']),
                'quiz' => ['title' => $quiz['title'], 'time_limit' => $quiz['time_limit'], 'instructions' => $quiz['instructions']]
            ]);
            exit;
        }
    }

    // ── Attempt & Seed-Based Stable Shuffle Retrieval ────────────────────────
    $remaining_seconds = null;
    $current_tab_switches = 0;
    $saved_answers = new stdClass();
    $stored_question_order = null;
    $stored_options_order  = null;

    if (!$isTeacherOrAdmin) {
        $attQ = $conn->query("SELECT * FROM quiz_attempts WHERE quiz_id=$id AND student_code='$uc' AND status='in_progress' ORDER BY id DESC LIMIT 1");
        if ($attQ && $attQ->num_rows > 0) {
            $attempt = $attQ->fetch_assoc();
            $attempt_id = intval($attempt['id']);
            $shuffle_seed = intval($attempt['shuffle_seed'] ?? 0);
            $stored_question_order = json_decode($attempt['question_order'] ?? '[]', true);
            $stored_options_order  = json_decode($attempt['options_order'] ?? '{}', true);

            $started_time = strtotime($attempt['started_at'] ?? $attempt['start_time'] ?? 'now');
            $last_hb = !empty($attempt['last_heartbeat']) ? strtotime($attempt['last_heartbeat']) : $started_time;
            $now = time();
            $disconnect_gap = $now - $last_hb;
            $total_paused = intval($attempt['total_paused_seconds'] ?? 0);

            if ($disconnect_gap > 15) {
                $max_grace_pause = 900;
                $total_paused += min($max_grace_pause, $disconnect_gap);
            }

            $current_tab_switches = intval($attempt['tab_switches'] ?? 0);
            $total_wall_seconds = $now - $started_time;
            $active_elapsed_seconds = max(0, $total_wall_seconds - $total_paused);
            $time_limit_mins = intval($quiz['time_limit'] ?? 0);

            if ($time_limit_mins > 0) {
                $total_limit_secs = $time_limit_mins * 60;
                $remaining_seconds = max(0, $total_limit_secs - $active_elapsed_seconds);
            }

            $conn->query("UPDATE quiz_attempts SET last_heartbeat=NOW(), total_paused_seconds=$total_paused WHERE id=$attempt_id");
            $saved_answers = json_decode($attempt['answers'] ?? '{}') ?: new stdClass();
        } else {
            // New Student Attempt: Generate seed & store deterministic layout
            $shuffle_seed = mt_rand(100000, 999999);
            $time_limit_mins = intval($quiz['time_limit'] ?? 0);
            $remaining_seconds = $time_limit_mins > 0 ? ($time_limit_mins * 60) : null;
            $current_tab_switches = 0;
            $saved_answers = new stdClass();

            $conn->query("INSERT INTO quiz_attempts (
                quiz_id, student_code, started_at, last_heartbeat, total_paused_seconds,
                tab_switches, status, shuffle_seed
            ) VALUES (
                $id, '$uc', NOW(), NOW(), 0, 0, 'in_progress', $shuffle_seed
            )");
            $attempt_id = $conn->insert_id;
        }
    } else {
        $shuffle_seed = 123456;
    }

    // Load questions from database
    $qsQ = $conn->query("SELECT * FROM quiz_questions WHERE quiz_id=$id ORDER BY id ASC");
    $rawQuestionsMap = [];
    $rawQuestionIds = [];

    while ($r = $qsQ->fetch_assoc()) {
        $qId = intval($r['id']);
        $rawQuestionIds[] = $qId;

        $optsData = json_decode($r['options_data'] ?? '[]', true) ?: [];
        if (empty($optsData)) {
            $legacyOpts = json_decode($r['options'] ?? '[]', true);
            if (!is_array($legacyOpts) && !empty($r['options'])) {
                $legacyOpts = (strpos($r['options'], ',') !== false) ? array_map('trim', explode(',', $r['options'])) : [$r['options']];
            }
            if (is_array($legacyOpts)) {
                foreach ($legacyOpts as $k => $oText) {
                    $optsData[] = [
                        'id'   => sprintf("opt-%d-%02d", $qId, $k + 1),
                        'text' => trim(preg_replace('/^[a-zA-Z0-9][\.\)\:\-\s]+/u', '', trim((string)$oText)))
                    ];
                }
            }
        }

        $r['options_data'] = $optsData;
        $r['matching_pairs'] = json_decode($r['matching_pairs'] ?? '[]', true) ?: [];
        $r['rubric_json'] = json_decode($r['rubric_json'] ?? '[]', true) ?: [];
        $r['required_concepts'] = json_decode($r['required_concepts'] ?? '[]', true) ?: [];
        $rawQuestionsMap[$qId] = $r;
    }

    // Deterministic Question & Option Shuffling
    $doShuffleQ = intval($quiz['shuffle_questions'] ?? 0) === 1 || intval($quiz['randomize_student'] ?? 0) === 1;
    $doShuffleA = (isset($quiz['shuffle_answers']) && intval($quiz['shuffle_answers']) === 0) ? false : true;
    $doShuffleM = (isset($quiz['shuffle_matching']) && intval($quiz['shuffle_matching']) === 0) ? false : true;
    $doShuffleTF= intval($quiz['shuffle_tf'] ?? 0) === 1;

    $orderedQIds = $rawQuestionIds;
    if ($stored_question_order && is_array($stored_question_order) && !empty($stored_question_order)) {
        $orderedQIds = $stored_question_order;
    } elseif ($doShuffleQ && !$isTeacherOrAdmin) {
        deterministicArrayShuffle($orderedQIds, $shuffle_seed);
    }

    $finalQuestions = [];
    $newOptionsOrder = [];

    foreach ($orderedQIds as $qId) {
        if (!isset($rawQuestionsMap[$qId])) continue;
        $q = $rawQuestionsMap[$qId];
        $type = strtolower(trim($q['question_type'] ?? 'multiple_choice'));

        $optsData = $q['options_data'] ?? [];
        if ($stored_options_order && isset($stored_options_order[$qId])) {
            $optIdMap = [];
            foreach ($optsData as $od) $optIdMap[$od['id']] = $od;
            $reorderedOpts = [];
            foreach ($stored_options_order[$qId] as $savedOptId) {
                if (isset($optIdMap[$savedOptId])) $reorderedOpts[] = $optIdMap[$savedOptId];
            }
            $optsData = !empty($reorderedOpts) ? $reorderedOpts : $optsData;
        } elseif ($doShuffleA && in_array($type, ['multiple_choice', 'multi_select']) && !$isTeacherOrAdmin) {
            deterministicArrayShuffle($optsData, $shuffle_seed + $qId);
        }

        $newOptionsOrder[$qId] = array_column($optsData, 'id');

        // Matching Column B shuffling
        $matchingPairs = $q['matching_pairs'] ?? [];
        $colBCandidates = [];
        foreach ($matchingPairs as $mp) {
            $colBCandidates[] = [
                'target_id'   => $mp['col_b_id'] ?? ($mp['target_id'] ?? ''),
                'target_text' => $mp['col_b_text'] ?? ($mp['target_text'] ?? '')
            ];
        }
        if ($doShuffleM && !$isTeacherOrAdmin) {
            deterministicArrayShuffle($colBCandidates, $shuffle_seed + $qId + 99);
        }

        $itemPayload = [
            'id'                  => $qId,
            'question_uid'        => $q['question_uid'] ?: sprintf("Q-%03d", $qId),
            'question_text'       => $q['question_text'],
            'topic'               => $q['topic'] ?: 'General',
            'question_type'       => $type,
            'points'              => floatval($q['points'] ?? 1),
            'options_data'        => $optsData,
            'matching_candidates' => $colBCandidates,
            'matching_pairs'      => $matchingPairs,
            'rubric'              => $q['rubric_json'],
            'required_concepts'   => $q['required_concepts']
        ];

        // Include answer key ONLY for teachers/admins
        if ($isTeacherOrAdmin) {
            $itemPayload['correct_option_ids'] = json_decode($q['correct_option_ids'] ?? '[]', true);
            $itemPayload['truth_value']        = $q['truth_value'];
            $itemPayload['incorrect_phrase']   = $q['incorrect_phrase'];
            $itemPayload['correct_replacement']= $q['correct_replacement'];
            $itemPayload['acceptable_answers'] = json_decode($q['acceptable_answers'] ?? '[]', true);
            $itemPayload['correct_answer']     = $q['correct_answer'];
        }

        $finalQuestions[] = $itemPayload;
    }

    // Persist seeded orders to attempt row
    if (!$isTeacherOrAdmin && !empty($attempt_id) && empty($stored_question_order)) {
        $qOrderJson = $conn->real_escape_string(json_encode($orderedQIds));
        $optOrderJson = $conn->real_escape_string(json_encode($newOptionsOrder));
        $conn->query("UPDATE quiz_attempts SET question_order='$qOrderJson', options_order='$optOrderJson' WHERE id=$attempt_id");
    }

    echo json_encode([
        'success'           => true,
        'quiz'              => [
            'title'                      => $quiz['title'],
            'time_limit'                 => $quiz['time_limit'],
            'instructions'               => $quiz['instructions'],
            'due_date'                   => $quiz['due_date'],
            'multi_select_scoring_mode'  => $quiz['multi_select_scoring_mode'] ?? 'partial_credit'
        ],
        'questions'         => $finalQuestions,
        'remaining_seconds' => $remaining_seconds,
        'tab_switches'      => $current_tab_switches,
        'saved_answers'     => $saved_answers
    ]);
    exit;
}

// ── Student: Save Draft Answers & Heartbeat ─────────────────────────────────
if ($action === 'heartbeat' || $action === 'save_draft') {
    $quiz_id = intval($_POST['quiz_id'] ?? 0);
    $answers = isset($_POST['answers']) ? json_decode($_POST['answers'], true) : null;
    if ($answers !== null) {
        $ans_json = $conn->real_escape_string(json_encode($answers));
        $conn->query("UPDATE quiz_attempts SET answers='$ans_json', last_heartbeat=NOW() WHERE quiz_id=$quiz_id AND student_code='$uc' AND status='in_progress'");
    } else {
        $conn->query("UPDATE quiz_attempts SET last_heartbeat=NOW() WHERE quiz_id=$quiz_id AND student_code='$uc' AND status='in_progress'");
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── Student: Log Tab Violation ──────────────────────────────────────────────
if ($action === 'log_violation') {
    $quiz_id = intval($_POST['quiz_id'] ?? 0);
    $conn->query("UPDATE quiz_attempts SET tab_switches = tab_switches + 1 WHERE quiz_id=$quiz_id AND student_code='$uc' AND status='in_progress'");
    $chk = $conn->query("SELECT tab_switches FROM quiz_attempts WHERE quiz_id=$quiz_id AND student_code='$uc' AND status='in_progress' ORDER BY id DESC LIMIT 1");
    $sw = 0;
    if ($chk && $chk->num_rows > 0) $sw = intval($chk->fetch_assoc()['tab_switches']);
    echo json_encode(['success' => true, 'tab_switches' => $sw, 'limit_reached' => ($sw >= 3)]);
    exit;
}

// ── Student: Authoritative Server-Side Quiz Submission & Grading ────────────
if ($action === 'submit') {
    $quiz_id = intval($_POST['quiz_id'] ?? 0);
    $answers = json_decode($_POST['answers'] ?? '{}', true) ?: [];

    $chk = $conn->query("SELECT id FROM quiz_submissions WHERE quiz_id=$quiz_id AND student_code='$uc'");
    if ($chk && $chk->num_rows > 0) {
        echo json_encode(['success' => false, 'msg' => 'Already submitted']);
        exit;
    }

    $qz = $conn->query("SELECT * FROM quizzes WHERE id=$quiz_id");
    if (!$qz || $qz->num_rows === 0) { echo json_encode(['success' => false, 'msg' => 'Quiz not found']); exit; }
    $quiz = $qz->fetch_assoc();
    $class_id = intval($quiz['class_id'] ?? 0);
    $msScoringMode = $quiz['multi_select_scoring_mode'] ?? 'partial_credit';

    // Fetch module text for essay evaluation
    $modText = "";
    if (!empty($quiz['module_id'])) {
        $maQ = $conn->query("SELECT extracted_text FROM class_material_analysis WHERE module_id=".intval($quiz['module_id']));
        if ($maQ && $maQ->num_rows > 0) $modText = $maQ->fetch_assoc()['extracted_text'] ?? '';
    }

    // Grade all 8 question types authoritatively
    $qsQ = $conn->query("SELECT * FROM quiz_questions WHERE quiz_id=$quiz_id ORDER BY id ASC");
    $totalEarned = 0.0;
    $totalPossible = 0.0;
    $topicScores = [];
    $aiSuggestedScores = [];
    $rubricScoresMap   = [];
    $essayFeedbacksMap = [];

    while ($q = $qsQ->fetch_assoc()) {
        $qId = intval($q['id']);
        $pts = floatval($q['points'] ?? 1);
        if ($pts <= 0) $pts = 1.0;
        $totalPossible += $pts;

        $type = strtolower(trim($q['question_type'] ?? 'multiple_choice'));
        $topic = trim($q['topic'] ?: 'General');
        $qEarned = 0.0;

        $given = $answers[$qId] ?? null;

        // 1. Single Multiple Choice
        if ($type === 'multiple_choice') {
            $correctOptionIds = json_decode($q['correct_option_ids'] ?? '[]', true) ?: [];
            $targetOptId = $correctOptionIds[0] ?? '';
            $submittedOptId = is_string($given) ? trim($given) : '';

            if ($targetOptId && strcasecmp($submittedOptId, $targetOptId) === 0) {
                $qEarned = $pts;
            } elseif ($q['correct_answer'] && strcasecmp($submittedOptId, trim($q['correct_answer'])) === 0) {
                $qEarned = $pts;
            }
        }
        // 2. Multi-Select Multiple Choice
        elseif ($type === 'multi_select' || $type === 'multiple_answers') {
            $correctOptionIds = json_decode($q['correct_option_ids'] ?? '[]', true) ?: [];
            $selectedOpts = is_array($given) ? $given : (is_string($given) ? json_decode($given, true) ?: [$given] : []);

            $correctCount = 0;
            $incorrectCount = 0;
            foreach ($selectedOpts as $so) {
                if (in_array($so, $correctOptionIds)) $correctCount++;
                else $incorrectCount++;
            }

            // Automatic zero points if any wrong selection or missed option (exact match required)
            if (count($correctOptionIds) > 0 && $incorrectCount === 0 && $correctCount === count($correctOptionIds)) {
                $qEarned = $pts;
            } else {
                $qEarned = 0.0;
            }
        }
        // 3. True / False
        elseif ($type === 'true_false') {
            $truthVal = intval($q['truth_value'] ?? 1);
            $givenStr = strtolower(trim((string)$given));
            $isGivenTrue = in_array($givenStr, ['true', 't', '1']);
            $isGivenFalse = in_array($givenStr, ['false', 'f', '0']);

            if (($truthVal == 1 && $isGivenTrue) || ($truthVal == 0 && $isGivenFalse)) {
                $qEarned = $pts;
            }
        }
        // 4. Modified True / False
        elseif ($type === 'modified_true_false') {
            $truthVal = intval($q['truth_value'] ?? 1);
            $givenObj = is_array($given) ? $given : (is_string($given) ? json_decode($given, true) ?: ['truth' => $given] : []);
            $givenTruth = strtolower(trim($givenObj['truth'] ?? ''));
            $givenCorr  = strtolower(trim($givenObj['correction'] ?? ($givenObj['replacement'] ?? '')));

            if ($truthVal == 1) {
                if (in_array($givenTruth, ['true', 't', '1'])) $qEarned = $pts;
            } else {
                // False statement: 1 pt for recognizing FALSE + 1 pt for correct replacement
                $halfPts = round($pts / 2.0, 2);
                if (in_array($givenTruth, ['false', 'f', '0'])) {
                    $qEarned += $halfPts;
                    $expectedCorr = strtolower(trim($q['correct_replacement'] ?? ''));
                    if ($expectedCorr && (strpos($givenCorr, $expectedCorr) !== false || levenshtein($givenCorr, $expectedCorr) <= 2)) {
                        $qEarned += ($pts - $halfPts);
                    }
                }
            }
        }
        // 5. Identification
        elseif ($type === 'identification') {
            $givenText = strtolower(trim((string)$given));
            $correctText = strtolower(trim($q['correct_answer'] ?? ''));
            $acceptable = array_map('strtolower', array_map('trim', json_decode($q['acceptable_answers'] ?? '[]', true) ?: []));
            $acceptable[] = $correctText;

            foreach ($acceptable as $acc) {
                if ($acc !== '' && ($givenText === $acc || levenshtein($givenText, $acc) <= 1)) {
                    $qEarned = $pts;
                    break;
                }
            }
        }
        // 6. Enumeration
        elseif ($type === 'enumeration') {
            $givenItems = is_array($given) ? $given : preg_split('/[,;\n\r]+/u', (string)$given, -1, PREG_SPLIT_NO_EMPTY);
            $cleanGiven = array_unique(array_values(array_filter(array_map('strtolower', array_map('trim', $givenItems)))));

            $expected = json_decode($q['acceptable_answers'] ?? '[]', true) ?: (preg_split('/[,;\n\r]+/u', (string)($q['correct_answer'] ?? ''), -1, PREG_SPLIT_NO_EMPTY));
            $cleanExp = array_unique(array_values(array_filter(array_map('strtolower', array_map('trim', $expected)))));

            $matchedCount = 0;
            foreach ($cleanGiven as $cg) {
                foreach ($cleanExp as $ce) {
                    if ($cg === $ce || strpos($cg, $ce) !== false || levenshtein($cg, $ce) <= 1) {
                        $matchedCount++;
                        break;
                    }
                }
            }
            $reqCount = max(1, count($cleanExp));
            $qEarned = round($pts * min(1.0, $matchedCount / $reqCount), 2);
        }
        // 7. Matching
        elseif ($type === 'matching' || (!empty($q['matching_pairs']) && $q['matching_pairs'] !== '[]') || (is_array($given) && count($given) > 0 && !isset($given[0]))) {
            $matchingPairs = json_decode($q['matching_pairs'] ?? '[]', true) ?: [];
            $givenMatches = is_array($given) ? $given : (json_decode((string)$given, true) ?: []);

            if (empty($matchingPairs) && !empty($q['correct_answer'])) {
                // Parse pairs like "1-C, 2-A, 3-B" or "1:C, 2:A, 3:B"
                $rawCorr = trim($q['correct_answer']);
                $pairsList = preg_split('/[,;\n]+/', $rawCorr);
                foreach ($pairsList as $idx => $pStr) {
                    if (preg_match('/([0-9a-zA-Z_\-]+)\s*[\-\:\=\>]\s*([0-9a-zA-Z_\-]+)/i', trim($pStr), $m)) {
                        $matchingPairs[] = [
                            'col_a_id' => 'a-' . trim($m[1]),
                            'col_b_id' => 'b-' . strtoupper(trim($m[2])),
                            'col_b_text' => trim($m[2])
                        ];
                    }
                }
            }

            $totalPairs = max(1, count($matchingPairs));
            $pairPts = $pts / $totalPairs;
            $correctPairs = 0;

            foreach ($matchingPairs as $mpIdx => $mp) {
                $aId = $mp['col_a_id'] ?? ($mp['item_id'] ?? ('a-' . ($mpIdx + 1)));
                $targetBId = $mp['col_b_id'] ?? ($mp['target_id'] ?? '');
                $targetBText = strtolower(trim($mp['col_b_text'] ?? ''));
                $studentChosen = $givenMatches[$aId] ?? ($givenMatches[$mpIdx] ?? ($givenMatches['pair_' . $mpIdx] ?? ''));

                if ($studentChosen) {
                    $studentChosenLower = strtolower(trim((string)$studentChosen));
                    if ($targetBId && (strcasecmp($studentChosen, $targetBId) === 0 || strcasecmp($studentChosenLower, strtolower($targetBId)) === 0)) {
                        $correctPairs++;
                    } elseif ($targetBText && (strpos($studentChosenLower, $targetBText) !== false || levenshtein($studentChosenLower, $targetBText) <= 1)) {
                        $correctPairs++;
                    }
                }
            }
            $qEarned = round($pairPts * $correctPairs, 2);
        }
        // 8. Essay (Semantic AI Grading)
        elseif ($type === 'essay') {
            $reqConcepts = json_decode($q['required_concepts'] ?? '[]', true) ?: [];
            $rubric = json_decode($q['rubric_json'] ?? '[]', true) ?: [];
            $eval = evaluateEssaySemantic($given, $modText, $q['question_text'], $reqConcepts, $rubric, $pts);

            $qEarned = $eval['score'];
            $aiSuggestedScores[$qId] = $qEarned;
            $rubricScoresMap[$qId]   = $eval['rubric_scores'];
            $essayFeedbacksMap[$qId] = $eval['feedback'];
        }

        $totalEarned += $qEarned;

        if (!isset($topicScores[$topic])) $topicScores[$topic] = ['earned' => 0.0, 'available' => 0.0];
        $topicScores[$topic]['earned'] += $qEarned;
        $topicScores[$topic]['available'] += $pts;
    }

    $finalScore = round($totalEarned, 2);
    $ansJson = $conn->real_escape_string(json_encode($answers));
    $aiScoresJson = $conn->real_escape_string(json_encode($aiSuggestedScores));
    $rubricJson = $conn->real_escape_string(json_encode($rubricScoresMap));
    $feedbackJson = $conn->real_escape_string(json_encode($essayFeedbacksMap));
    $tabSw = intval($_POST['tab_switches'] ?? 0);
    $fsEx = intval($_POST['fullscreen_exits'] ?? 0);
    $modVerVal = $conn->real_escape_string($quiz['module_version'] ?? '1.0');

    // Update submission
    $subIns = $conn->query("INSERT INTO quiz_submissions (
        quiz_id, student_code, answers, score, final_score, total_points, submitted_at,
        tab_switches, fullscreen_exits, essay_scores, ai_suggested_scores,
        rubric_scores, essay_feedback, module_version_used
    ) VALUES (
        $quiz_id, '$uc', '$ansJson', $finalScore, $finalScore, $totalPossible, NOW(),
        $tabSw, $fsEx, '$aiScoresJson', '$aiScoresJson',
        '$rubricJson', '$feedbackJson', '$modVerVal'
    ) ON DUPLICATE KEY UPDATE
        score = $finalScore, final_score = $finalScore, answers = '$ansJson', ai_suggested_scores = '$aiScoresJson',
        rubric_scores = '$rubricJson', essay_feedback = '$feedbackJson', tab_switches = $tabSw, submitted_at = NOW()");

    if(!$subIns){
        // Robust fallback with minimal essential columns
        $conn->query("INSERT INTO quiz_submissions (
            quiz_id, student_code, answers, score, final_score, total_points, submitted_at, tab_switches, fullscreen_exits
        ) VALUES (
            $quiz_id, '$uc', '$ansJson', $finalScore, $finalScore, $totalPossible, NOW(), $tabSw, $fsEx
        ) ON DUPLICATE KEY UPDATE score = $finalScore, final_score = $finalScore, answers = '$ansJson'");
    }

    $conn->query("UPDATE quiz_attempts SET status='submitted', tab_switches=$tabSw WHERE quiz_id=$quiz_id AND student_code='$uc' AND status='in_progress'");

    // Update topic performance table
    foreach ($topicScores as $tName => $tData) {
        $tEarned = floatval($tData['earned']);
        $tAvail  = floatval($tData['available']);
        $escTopic = $conn->real_escape_string($tName);
        $conn->query("INSERT INTO topic_performance (
            class_id, student_code, topic, total_points_earned, total_points_available, attempts, last_attempt
        ) VALUES (
            $class_id, '$uc', '$escTopic', $tEarned, $tAvail, 1, NOW()
        ) ON DUPLICATE KEY UPDATE
            total_points_earned = total_points_earned + $tEarned,
            total_points_available = total_points_available + $tAvail,
            attempts = attempts + 1,
            last_attempt = NOW()");
    }

    // Auto-sync into class_record_scores
    $syncQ = $conn->query("SELECT col.id, col.max_score FROM class_record_columns col
                           WHERE col.quiz_id = $quiz_id AND col.class_id = $class_id LIMIT 1");
    if ($syncQ && $syncQ->num_rows > 0) {
        $colRow = $syncQ->fetch_assoc();
        $col_id = intval($colRow['id']);
        $maxScore = floatval($colRow['max_score']);
        $scaledScore = ($totalPossible > 0 && $maxScore > 0) ? round(($finalScore / $totalPossible) * $maxScore, 2) : $finalScore;
        $conn->query("INSERT INTO class_record_scores (column_id, class_id, student_code, score)
                      VALUES ($col_id, $class_id, '$uc', $scaledScore)
                      ON DUPLICATE KEY UPDATE score = $scaledScore");
    }

    echo json_encode([
        'success' => true,
        'score'   => $finalScore,
        'total'   => $totalPossible,
        'msg'     => "Score: $finalScore / $totalPossible"
    ]);
    exit;
}

// ── Teacher: Delete Quiz ───────────────────────────────────────────────────
if ($action === 'delete' && $role === 'TEACHER') {
    $rawIds = trim($_POST['id'] ?? '0');
    $idArr = array_map('intval', explode(',', $rawIds));
    $cleanIds = implode(',', array_filter($idArr));
    if (!$cleanIds) { echo json_encode(['success' => false, 'msg' => 'Invalid quiz ID']); exit; }

    $conn->query("DELETE FROM quiz_questions WHERE quiz_id IN ($cleanIds)");
    $conn->query("DELETE FROM quiz_submissions WHERE quiz_id IN ($cleanIds)");
    $conn->query("DELETE FROM quiz_attempts WHERE quiz_id IN ($cleanIds)");
    $conn->query("DELETE FROM quizzes WHERE id IN ($cleanIds) AND teacher_code='$uc'");
    echo json_encode(['success' => true]);
    exit;
}

// ── Teacher: Toggle active ─────────────────────────────────────────────────
if ($action === 'toggle' && $role === 'TEACHER') {
    $rawIds = trim($_POST['id'] ?? '0');
    $val = intval($_POST['is_active'] ?? 0);
    $idArr = array_map('intval', explode(',', $rawIds));
    $cleanIds = implode(',', array_filter($idArr));
    if (!$cleanIds) { echo json_encode(['success' => false, 'msg' => 'Invalid quiz ID']); exit; }

    $conn->query("UPDATE quizzes SET is_active=$val WHERE id IN ($cleanIds) AND teacher_code='$uc'");
    echo json_encode(['success' => true]);
    exit;
}

// ── Teacher / Admin: Get Submissions for a Quiz ─────────────────────────────
if ($action === 'get_submissions') {
    $rawQuizId = trim($_POST['quiz_id'] ?? $_GET['quiz_id'] ?? '0');
    $idArr = array_map('intval', explode(',', $rawQuizId));
    $cleanIds = implode(',', array_filter($idArr));
    if (!$cleanIds) $cleanIds = '0';

    $qz = $conn->query("SELECT q.id, q.title, q.class_id, q.teacher_code FROM quizzes q WHERE q.id IN ($cleanIds) LIMIT 1");
    if (!$qz || $qz->num_rows === 0) {
        echo json_encode(['success' => false, 'msg' => 'Quiz not found']);
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

    if ($subsQ) {
        while ($row = $subsQ->fetch_assoc()) {
            $row['first_name'] = $row['first_name'] ?: 'Student';
            $row['last_name']  = $row['last_name'] ?: $row['student_code'];
            $row['user_code']  = $row['student_code'];
            $submissions[] = $row;
            if (floatval($row['score']) > $highScore) $highScore = floatval($row['score']);
            $sumScore += floatval($row['percentage']);
            if (intval($row['tab_switches'] ?? 0) > 0 || intval($row['fullscreen_exits'] ?? 0) > 0) {
                $violationCount++;
            }
        }
    }

    $count = count($submissions);
    $avgPct = $count > 0 ? round($sumScore / $count, 1) : 0;

    $primaryQuizId = intval($quiz['id']);
    $qsQ = $conn->query("SELECT * FROM quiz_questions WHERE quiz_id = $primaryQuizId ORDER BY id ASC");
    $questions = [];
    $seenQuestions = [];
    if ($qsQ) {
        while ($qr = $qsQ->fetch_assoc()) {
            $opts = json_decode($qr['options_data'] ?? '[]', true) ?: [];
            if (empty($opts) && !empty($qr['options'])) {
                $rawOpts = json_decode($qr['options'], true) ?: [];
                foreach ($rawOpts as $oi => $ot) {
                    $opts[] = ['id' => 'opt-'.$oi, 'text' => $ot];
                }
            }
            $mPairs = json_decode($qr['matching_pairs'] ?? '[]', true) ?: [];
            $questions[] = [
                'id'                 => intval($qr['id']),
                'question_uid'       => $qr['question_uid'] ?: sprintf("Q-%03d", $qr['id']),
                'question_text'      => $qr['question_text'],
                'topic'              => $qr['topic'] ?: 'General',
                'question_type'      => strtolower(trim($qr['question_type'] ?? 'multiple_choice')),
                'points'             => floatval($qr['points'] ?? 1),
                'correct_answer'     => $qr['correct_answer'] ?? '',
                'options'            => array_column($opts, 'text') ?: (json_decode($qr['options'] ?? '[]', true) ?: []),
                'options_data'       => $opts,
                'correct_option_ids' => json_decode($qr['correct_option_ids'] ?? '[]', true) ?: [],
                'matching_pairs'     => $mPairs,
                'acceptable_answers' => json_decode($qr['acceptable_answers'] ?? '[]', true) ?: []
            ];
        }
    }

    echo json_encode([
        'success'     => true,
        'quiz'        => $quiz,
        'stats'       => [
            'submission_count' => $count,
            'avg_pct'          => $avgPct,
            'high_score'       => $highScore,
            'violation_count'  => $violationCount,
            'question_count'   => count($questions)
        ],
        'questions'   => $questions,
        'submissions' => $submissions
    ]);
    exit;
}

// ── Teacher / Student: Get Detailed Student Answers for Review ───────────────
if ($action === 'get_student_answers') {
    $quiz_id      = intval($_POST['quiz_id'] ?? $_GET['quiz_id'] ?? 0);
    $student_code = $conn->real_escape_string(trim($_POST['student_code'] ?? $_GET['student_code'] ?? ''));

    if (!$quiz_id || !$student_code) {
        echo json_encode(['success' => false, 'msg' => 'Quiz ID and Student Code required']);
        exit;
    }

    $subQ = $conn->query("
        SELECT s.*, u.first_name, u.last_name, u.user_code
        FROM quiz_submissions s
        LEFT JOIN users u ON s.student_code = u.user_code
        WHERE s.quiz_id=$quiz_id AND s.student_code='$student_code'
        LIMIT 1
    ");
    if (!$subQ || $subQ->num_rows === 0) {
        echo json_encode(['success' => false, 'msg' => 'No submission found']);
        exit;
    }
    $sub = $subQ->fetch_assoc();

    $answers = json_decode($sub['answers'] ?? '{}', true) ?: [];
    $aiScores = json_decode($sub['ai_suggested_scores'] ?? '{}', true) ?: (json_decode($sub['essay_scores'] ?? '{}', true) ?: []);
    $teacherOverrides = json_decode($sub['teacher_overrides'] ?? '{}', true) ?: [];
    $teacherFeedbacks = json_decode($sub['teacher_feedback'] ?? '{}', true) ?: [];
    $rubricScoresMap  = json_decode($sub['rubric_scores'] ?? '{}', true) ?: [];
    $essayFeedbacksMap= json_decode($sub['essay_feedback'] ?? '{}', true) ?: [];

    $qsQ = $conn->query("SELECT * FROM quiz_questions WHERE quiz_id=$quiz_id ORDER BY id ASC");
    $reviewQuestions = [];

    while ($qr = $qsQ->fetch_assoc()) {
        $qId = intval($qr['id']);
        $type = strtolower(trim($qr['question_type'] ?? 'multiple_choice'));
        $pts = floatval($qr['points'] ?? 1);
        $given = $answers[$qId] ?? null;

        $mPairs = json_decode($qr['matching_pairs'] ?? '[]', true) ?: [];
        $opts = json_decode($qr['options_data'] ?? '[]', true) ?: [];
        if (empty($opts) && !empty($qr['options'])) {
            $rawOpts = json_decode($qr['options'], true) ?: [];
            foreach ($rawOpts as $oi => $ot) {
                $opts[] = ['id' => 'opt-'.$oi, 'text' => $ot];
            }
        }

        $isCorrect = false;
        $earnedPts = 0.0;

        // Evaluate question based on type
        if ($type === 'multiple_choice') {
            $correctOptionIds = json_decode($qr['correct_option_ids'] ?? '[]', true) ?: [];
            $targetOptId = $correctOptionIds[0] ?? '';
            $submittedOptId = is_string($given) ? trim($given) : '';

            if ($targetOptId && strcasecmp($submittedOptId, $targetOptId) === 0) {
                $isCorrect = true; $earnedPts = $pts;
            } elseif ($qr['correct_answer'] && strcasecmp($submittedOptId, trim($qr['correct_answer'])) === 0) {
                $isCorrect = true; $earnedPts = $pts;
            }
        } elseif ($type === 'multi_select' || $type === 'multiple_answers') {
            $correctOptionIds = json_decode($qr['correct_option_ids'] ?? '[]', true) ?: [];

            // Normalize given answer — handle both array ["opt-id"] and object {"a-1":"opt-id"} formats
            $rawGiven = $given;
            if (is_array($rawGiven) && array_keys($rawGiven) !== range(0, count($rawGiven) - 1)) {
                // Associative array (object format) — extract values
                $selectedOpts = array_values($rawGiven);
            } elseif (is_array($rawGiven)) {
                $selectedOpts = $rawGiven;
            } elseif (is_string($rawGiven) && $rawGiven !== '') {
                $decoded = json_decode($rawGiven, true);
                if (is_array($decoded)) {
                    // Check if associative (object) or indexed (array)
                    if (array_keys($decoded) !== range(0, count($decoded) - 1)) {
                        $selectedOpts = array_values($decoded);
                    } else {
                        $selectedOpts = $decoded;
                    }
                } else {
                    $selectedOpts = [$rawGiven];
                }
            } else {
                $selectedOpts = [];
            }

            $correctCount = 0;
            $incorrectCount = 0;
            foreach ($selectedOpts as $so) {
                if (in_array($so, $correctOptionIds)) $correctCount++;
                else $incorrectCount++;
            }
            // Automatic zero points if any wrong selection or missed option
            $isCorrect = (count($correctOptionIds) > 0 && $correctCount === count($correctOptionIds) && $incorrectCount === 0);
            $earnedPts = $isCorrect ? $pts : 0.0;
        } elseif ($type === 'true_false') {
            $truthVal = intval($qr['truth_value'] ?? 1);
            $givenStr = strtolower(trim((string)$given));
            $isGivenTrue = in_array($givenStr, ['true', 't', '1']);
            $isGivenFalse = in_array($givenStr, ['false', 'f', '0']);

            if (($truthVal == 1 && $isGivenTrue) || ($truthVal == 0 && $isGivenFalse)) {
                $isCorrect = true; $earnedPts = $pts;
            }
        } elseif ($type === 'modified_true_false') {
            $truthVal = intval($qr['truth_value'] ?? 1);
            $givenObj = is_array($given) ? $given : (is_string($given) ? json_decode($given, true) ?: ['truth' => $given] : []);
            $givenTruth = strtolower(trim($givenObj['truth'] ?? ''));
            $givenCorr  = strtolower(trim($givenObj['correction'] ?? ($givenObj['replacement'] ?? '')));

            if ($truthVal == 1) {
                if (in_array($givenTruth, ['true', 't', '1'])) { $isCorrect = true; $earnedPts = $pts; }
            } else {
                $halfPts = round($pts / 2.0, 2);
                if (in_array($givenTruth, ['false', 'f', '0'])) {
                    $earnedPts += $halfPts;
                    $expectedCorr = strtolower(trim($qr['correct_replacement'] ?? ''));
                    if ($expectedCorr && (strpos($givenCorr, $expectedCorr) !== false || levenshtein($givenCorr, $expectedCorr) <= 2)) {
                        $earnedPts += ($pts - $halfPts);
                        $isCorrect = true;
                    }
                }
            }
        } elseif ($type === 'identification') {
            $givenText = strtolower(trim((string)$given));
            $correctText = strtolower(trim($qr['correct_answer'] ?? ''));
            $acceptable = array_map('strtolower', array_map('trim', json_decode($qr['acceptable_answers'] ?? '[]', true) ?: []));
            $acceptable[] = $correctText;

            foreach ($acceptable as $acc) {
                if ($acc !== '' && ($givenText === $acc || levenshtein($givenText, $acc) <= 1)) {
                    $isCorrect = true; $earnedPts = $pts;
                    break;
                }
            }
        } elseif ($type === 'enumeration') {
            $correctItems = array_values(array_filter(array_map('strtolower', array_map('trim', explode(',', $qr['correct_answer'] ?? '')))));
            $givenItems   = is_array($given) ? $given : array_values(array_filter(array_map('strtolower', array_map('trim', explode(',', (string)$given)))));
            $matched = 0;
            foreach ($correctItems as $ci) {
                if (in_array($ci, $givenItems)) $matched++;
            }
            $earnedPts = count($correctItems) > 0 ? round($pts * ($matched / count($correctItems)), 2) : 0;
            $isCorrect = ($earnedPts >= $pts);
        } elseif ($type === 'matching' || !empty($mPairs)) {
            $matchingPairs = $mPairs ?: (json_decode($qr['matching_pairs'] ?? '[]', true) ?: []);
            $givenMatches  = is_array($given) ? $given : (is_string($given) ? json_decode($given, true) ?: [] : []);
            $totalPairs    = max(1, count($matchingPairs));
            $pairPts       = $pts / $totalPairs;
            $correctPairs  = 0;

            foreach ($matchingPairs as $mpIdx => $mp) {
                $aId = $mp['col_a_id'] ?? ($mp['item_id'] ?? ('a-' . ($mpIdx + 1)));
                $targetBId = $mp['col_b_id'] ?? ($mp['target_id'] ?? '');
                $targetBText = strtolower(trim($mp['col_b_text'] ?? ''));
                $studentChosen = $givenMatches[$aId] ?? ($givenMatches[$mpIdx] ?? ($givenMatches['pair_' . $mpIdx] ?? ''));

                if ($studentChosen) {
                    $studentChosenLower = strtolower(trim((string)$studentChosen));
                    if ($targetBId && (strcasecmp($studentChosen, $targetBId) === 0 || strcasecmp($studentChosenLower, strtolower($targetBId)) === 0)) {
                        $correctPairs++;
                    } elseif ($targetBText && (strpos($studentChosenLower, $targetBText) !== false || levenshtein($studentChosenLower, $targetBText) <= 1)) {
                        $correctPairs++;
                    }
                }
            }
            $earnedPts = round($pairPts * $correctPairs, 2);
            $isCorrect = ($correctPairs === count($matchingPairs) && count($matchingPairs) > 0);
        } elseif ($type === 'essay') {
            $aiSc = floatval($aiScores[$qId] ?? 0);
            $tSc  = isset($teacherOverrides[$qId]) ? floatval($teacherOverrides[$qId]) : null;
            $earnedPts = $tSc !== null ? $tSc : $aiSc;
            $isCorrect = ($earnedPts >= ($pts * 0.6));
        }

        $reviewQuestions[] = [
            'id'                => $qId,
            'question_uid'      => $qr['question_uid'] ?: sprintf("Q-%03d", $qId),
            'question_text'     => $qr['question_text'],
            'topic'             => $qr['topic'] ?: 'General',
            'question_type'     => $type,
            'points'            => $pts,
            'earned_points'     => $earnedPts,
            'is_correct'        => $isCorrect,
            'correct_answer'    => $qr['correct_answer'] ?? '',
            'options'           => array_column($opts, 'text') ?: (json_decode($qr['options'] ?? '[]', true) ?: []),
            'options_data'      => $opts,
            'correct_option_ids'=> json_decode($qr['correct_option_ids'] ?? '[]', true) ?: [],
            'matching_pairs'    => $mPairs,
            'truth_value'       => $qr['truth_value'],
            'incorrect_phrase'  => $qr['incorrect_phrase'],
            'correct_replacement'=> $qr['correct_replacement'],
            'rubric'            => json_decode($qr['rubric_json'] ?? '[]', true) ?: [],
            'required_concepts' => json_decode($qr['required_concepts'] ?? '[]', true) ?: [],
            'given_answer'      => $given,
            'ai_score'          => floatval($aiScores[$qId] ?? 0),
            'ai_feedback'       => $essayFeedbacksMap[$qId] ?? '',
            'rubric_scores'     => $rubricScoresMap[$qId] ?? [],
            'teacher_score'     => isset($teacherOverrides[$qId]) ? floatval($teacherOverrides[$qId]) : null,
            'teacher_feedback'  => $teacherFeedbacks[$qId] ?? ''
        ];
    }

    echo json_encode([
        'success'          => true,
        'quiz_id'          => $quiz_id,
        'student_code'     => $student_code,
        'student_name'     => trim(($sub['first_name'] ?? '') . ' ' . ($sub['last_name'] ?? '')),
        'score'            => floatval($sub['score']),
        'total_points'     => intval($sub['total_points']),
        'submitted_at'     => $sub['submitted_at'] ? date('M d, Y g:i A', strtotime($sub['submitted_at'])) : '—',
        'tab_switches'     => intval($sub['tab_switches'] ?? 0),
        'fullscreen_exits' => intval($sub['fullscreen_exits'] ?? 0),
        'questions'        => $reviewQuestions
    ]);
    exit;
}

// ── Teacher: Reset / Allow Retake ───────────────────────────────────────────
if (($action === 'allow_retake' || $action === 'reset_student_quiz') && in_array($role, ['TEACHER', 'ADMIN', 'SUPERADMIN'])) {
    $quiz_id = intval($_POST['quiz_id'] ?? 0);
    $student_code = $conn->real_escape_string(trim($_POST['student_code'] ?? ''));

    if (!$quiz_id || !$student_code) {
        echo json_encode(['success' => false, 'msg' => 'Quiz ID and Student Code required']);
        exit;
    }

    $qzInfo = $conn->query("SELECT title, teacher_code, term FROM quizzes WHERE id=$quiz_id LIMIT 1");
    $qMeta = $qzInfo ? $qzInfo->fetch_assoc() : null;

    if ($qMeta) {
        $qTitleEsc = $conn->real_escape_string($qMeta['title']);
        $qTcEsc    = $conn->real_escape_string($qMeta['teacher_code']);
        $qTermEsc  = $conn->real_escape_string($qMeta['term']);

        $conn->query("
            DELETE s FROM quiz_submissions s 
            JOIN quizzes q ON s.quiz_id = q.id 
            WHERE s.student_code='$student_code' 
              AND (s.quiz_id=$quiz_id OR (q.title='$qTitleEsc' AND q.teacher_code='$qTcEsc' AND q.term='$qTermEsc'))
        ");
        $conn->query("
            DELETE a FROM quiz_attempts a 
            JOIN quizzes q ON a.quiz_id = q.id 
            WHERE a.student_code='$student_code' 
              AND (a.quiz_id=$quiz_id OR (q.title='$qTitleEsc' AND q.teacher_code='$qTcEsc' AND q.term='$qTermEsc'))
        ");
        $conn->query("
            DELETE crs FROM class_record_scores crs 
            JOIN class_record_columns crc ON crs.column_id=crc.id 
            JOIN quizzes q ON crc.quiz_id=q.id 
            WHERE crs.student_code='$student_code' 
              AND (crc.quiz_id=$quiz_id OR (q.title='$qTitleEsc' AND q.teacher_code='$qTcEsc' AND q.term='$qTermEsc'))
        ");
    } else {
        $conn->query("DELETE FROM quiz_submissions WHERE quiz_id=$quiz_id AND student_code='$student_code'");
        $conn->query("DELETE FROM quiz_attempts WHERE quiz_id=$quiz_id AND student_code='$student_code'");
        $conn->query("DELETE crs FROM class_record_scores crs JOIN class_record_columns crc ON crs.column_id=crc.id WHERE crc.quiz_id=$quiz_id AND crs.student_code='$student_code'");
    }

    echo json_encode(['success' => true, 'msg' => 'Student quiz attempt reset. Student can now retake the quiz.']);
    exit;
}

echo json_encode(['success' => false, 'msg' => 'Invalid action']);
