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

if(!function_exists('evaluateEssayTopicRelevance')){
    function evaluateEssayTopicRelevance($essayText, $qTopic, $qPrompt, $qRubric, $moduleTopics, $maxPoints){
        $ans = trim((string)$essayText);
        $maxPts = floatval($maxPoints > 0 ? $maxPoints : 1);
        if(strlen($ans) < 6){
            return ['score' => 0, 'pct' => 0, 'is_related' => false, 'reason' => 'Empty or too short'];
        }

        $stopwords = [
            'the','a','an','and','or','but','if','because','as','what','which','this','that','these','those',
            'then','just','so','than','such','both','through','about','for','is','of','while','during','to',
            'what','who','which','why','how','all','any','both','each','few','more','most','other','some',
            'no','nor','not','only','own','same','so','than','too','very','can','will','just','should','now',
            'explain','describe','discuss','sentence','sentences','paragraph','words','your','own','in','on',
            'at','by','from','up','down','into','over','after','before','under','again','where','when','there',
            'give','list','name','state','define','sample','rubric','teacher','grading','please','write'
        ];

        $sourceText = strtolower($qTopic . ' ' . implode(' ', (array)$moduleTopics) . ' ' . $qPrompt . ' ' . $qRubric);
        $rawTokens = preg_split('/[\s,\.\?\!\(\)\:\;\"\'\-\_\/\–\—]+/u', $sourceText);
        $keywords = [];
        foreach($rawTokens as $t){
            $t = trim($t);
            if(strlen($t) >= 3 && !in_array($t, $stopwords)){
                $keywords[$t] = true;
            }
        }
        $keywordList = array_keys($keywords);

        $ansLower = strtolower($ans);
        $matched = [];
        foreach($keywordList as $kw){
            if(strpos($ansLower, $kw) !== false){
                $matched[$kw] = true;
            }
        }

        $matchCount = count($matched);
        $wordCount = count(preg_split('/\s+/u', $ans));

        $keyCoverage = 0;
        if(count($keywordList) > 0){
            $keyCoverage = min(1.0, $matchCount / min(5, count($keywordList)));
        }

        $substance = min(1.0, $wordCount / 16);

        $relevancePct = 0;
        if($matchCount > 0){
            $relevancePct = round(($keyCoverage * 65) + ($substance * 35));
            $relevancePct = min(100, max(35, $relevancePct));
        } else {
            if($substance >= 0.5){
                $relevancePct = 15;
            } else {
                $relevancePct = 0;
            }
        }

        $isRelated = ($relevancePct >= 40);
        $awardedPoints = 0;
        if($isRelated){
            $awardedPoints = round(($relevancePct / 100) * $maxPts, 1);
            if($awardedPoints < ($maxPts * 0.5) && $relevancePct >= 50){
                $awardedPoints = round($maxPts * 0.5, 1);
            }
        }

        return [
            'score'        => $awardedPoints,
            'pct'          => $relevancePct,
            'is_related'   => $isRelated,
            'matched_count'=> $matchCount,
            'matched_words'=> array_keys($matched)
        ];
    }
}

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
        SELECT s.id, s.quiz_id, s.answers, s.score, s.total_points, s.tab_switches, s.fullscreen_exits, s.submitted_at, s.essay_scores,
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
    $sub_id = intval($sub['id']);
    $actualQuizId = intval($sub['quiz_id']);

    $answers = json_decode($sub['answers'] ?? '{}', true);
    if(!is_array($answers)) $answers = [];

    $essayScoresMap = json_decode($sub['essay_scores'] ?? '{}', true);
    if(!is_array($essayScoresMap)) $essayScoresMap = [];

    // Get class module topics to test essay connection
    $classId = intval($quiz['class_id'] ?? 0);
    $moduleTopics = [];
    if($classId > 0){
        $mtq = $conn->query("SELECT title, topic, description FROM class_modules WHERE class_id=$classId");
        if($mtq){
            while($mr = $mtq->fetch_assoc()){
                if(!empty($mr['topic'])) $moduleTopics[] = trim($mr['topic']);
                if(!empty($mr['title'])) $moduleTopics[] = trim($mr['title']);
            }
        }
    }

    // Helper for evaluating any question submission accurately
    $evaluateQuestion = function($qr, $rawGiven, $essayScoresMap, $moduleTopics) use ($conn) {
        $qId      = intval($qr['id']);
        $type     = strtolower(trim($qr['question_type'] ?? 'multiple_choice'));
        $pts      = floatval($qr['points'] ?? 1);
        $correct  = trim((string)($qr['correct_answer'] ?? ''));

        $mPairs = json_decode($qr['matching_pairs'] ?? '[]', true) ?: [];
        $optsData = json_decode($qr['options_data'] ?? '[]', true) ?: [];
        $rawOpts = $qr['options'] ?? '';
        $opts = json_decode($rawOpts, true);
        if(!is_array($opts) && !empty($rawOpts)){
            if(strpos($rawOpts, ',') !== false){
                $opts = array_map('trim', explode(',', $rawOpts));
            } else {
                $opts = [$rawOpts];
            }
        }
        $opts = is_array($opts) ? array_values(array_filter($opts, function($o){ return $o !== ''; })) : [];
        if(empty($optsData) && !empty($opts)){
            foreach($opts as $oi => $ot){
                $optsData[] = ['id' => 'opt-' . $oi, 'text' => $ot];
            }
        }

        $correctOptionIds = json_decode($qr['correct_option_ids'] ?? '[]', true) ?: [];
        $acceptable = json_decode($qr['acceptable_answers'] ?? '[]', true) ?: [];

        $isCorrect = false;
        $earnedPts = 0.0;
        $relevancePct = 0;

        // Auto-detect matching format in correct_answer or prompt if marked as multi_select or matching
        $hasMatchingPattern = (!empty($mPairs)) || (is_string($correct) && preg_match('/[0-9a-zA-Z_\-]+\s*[\-\:\=\>]\s*[0-9a-zA-Z_\-]+/', $correct));

        if($type === 'essay'){
            $qTopic = trim($qr['topic'] ?? '');
            $qPrompt = trim($qr['question_text'] ?? '');
            $essayAns = is_string($rawGiven) ? trim($rawGiven) : (is_array($rawGiven) ? json_encode($rawGiven) : '');

            $eval = evaluateEssayTopicRelevance($essayAns, $qTopic, $qPrompt, $correct, $moduleTopics, $pts);
            $relevancePct = $eval['pct'];

            if(isset($essayScoresMap[$qId])){
                $earnedPts = floatval($essayScoresMap[$qId]);
                $isCorrect = ($earnedPts >= ($pts * 0.5));
            } else {
                $earnedPts = $eval['score'];
                $isCorrect = $eval['is_related'];
            }
        } elseif($type === 'matching' || ($type === 'multi_select' && $hasMatchingPattern && empty($correctOptionIds))){
            // Build matching pairs from correct_answer if matching_pairs JSON is empty
            if(empty($mPairs) && !empty($correct)){
                $pairsList = preg_split('/[,;\n]+/', $correct);
                foreach($pairsList as $pIdx => $pStr){
                    if(preg_match('/([0-9a-zA-Z_\-]+)\s*[\-\:\=\>]\s*([0-9a-zA-Z_\-]+)/i', trim($pStr), $m)){
                        $mPairs[] = [
                            'col_a_id'   => 'a-' . trim($m[1]),
                            'col_b_id'   => 'b-' . strtoupper(trim($m[2])),
                            'col_a_text' => trim($m[1]),
                            'col_b_text' => trim($m[2])
                        ];
                    }
                }
            }

            $givenMatches = [];
            if(is_array($rawGiven)){
                $givenMatches = $rawGiven;
            } elseif(is_string($rawGiven) && $rawGiven !== ''){
                $dec = json_decode($rawGiven, true);
                if(is_array($dec)){
                    $givenMatches = $dec;
                } else {
                    // String like "1-B, 2-C, 3-A"
                    $pList = preg_split('/[,;\n]+/', $rawGiven);
                    foreach($pList as $pStr){
                        if(preg_match('/([0-9a-zA-Z_\-]+)\s*[\-\:\=\>]\s*([0-9a-zA-Z_\-]+)/i', trim($pStr), $m)){
                            $givenMatches[trim($m[1])] = trim($m[2]);
                            $givenMatches['a-' . trim($m[1])] = trim($m[2]);
                        }
                    }
                }
            }

            $totalPairs = max(1, count($mPairs));
            $pairPts    = $pts / $totalPairs;
            $correctPairs = 0;

            foreach($mPairs as $mpIdx => $mp){
                $aId       = $mp['col_a_id'] ?? ($mp['item_id'] ?? ('a-' . ($mpIdx + 1)));
                $aText     = $mp['col_a_text'] ?? ($mp['left'] ?? ('a-' . ($mpIdx + 1)));
                $targetBId = $mp['col_b_id'] ?? ($mp['target_id'] ?? '');
                $targetBText = strtolower(trim($mp['col_b_text'] ?? ($mp['right'] ?? '')));

                // Look up student selection under various key formats
                $cleanAId = preg_replace('/^a\-/i', '', $aId);
                $cleanAText = strtolower(trim($aText));
                $studentChosen = $givenMatches[$aId] ?? ($givenMatches[$cleanAId] ?? ($givenMatches[$aText] ?? ($givenMatches[$cleanAText] ?? ($givenMatches[$mpIdx] ?? ($givenMatches['pair_' . $mpIdx] ?? '')))));

                // Also handle array of pair strings like ["1-B", "2-C", "3-A"]
                if(!$studentChosen && isset($givenMatches[$mpIdx]) && is_string($givenMatches[$mpIdx])){
                    if(preg_match('/[\-\:\=\>]\s*([0-9a-zA-Z_\-]+)/i', $givenMatches[$mpIdx], $pm)){
                        $studentChosen = trim($pm[1]);
                    }
                }

                if($studentChosen){
                    $studentChosenLower = strtolower(trim((string)$studentChosen));
                    $cleanTargetBId = strtolower(preg_replace('/^b\-/i', '', $targetBId));
                    $cleanStudentChosen = strtolower(preg_replace('/^b\-/i', '', $studentChosenLower));

                    if($targetBId && (strcasecmp($studentChosen, $targetBId) === 0 || strcasecmp($cleanStudentChosen, $cleanTargetBId) === 0)){
                        $correctPairs++;
                    } elseif($targetBText && (strcasecmp($studentChosenLower, $targetBText) === 0 || strcasecmp($cleanStudentChosen, $targetBText) === 0 || (strlen($targetBText) > 2 && strpos($studentChosenLower, $targetBText) !== false))){
                        $correctPairs++;
                    }
                }
            }

            $earnedPts = round($pairPts * $correctPairs, 2);
            $isCorrect = ($correctPairs === count($mPairs) && count($mPairs) > 0);
        } elseif($type === 'multi_select' || $type === 'multiple_answers'){
            // Normalize selected option IDs
            $selectedOpts = [];
            if(is_array($rawGiven)){
                if(array_keys($rawGiven) !== range(0, count($rawGiven) - 1)){
                    $selectedOpts = array_values($rawGiven);
                } else {
                    $selectedOpts = $rawGiven;
                }
            } elseif(is_string($rawGiven) && $rawGiven !== ''){
                $decoded = json_decode($rawGiven, true);
                if(is_array($decoded)){
                    $selectedOpts = (array_keys($decoded) !== range(0, count($decoded) - 1)) ? array_values($decoded) : $decoded;
                } else {
                    $selectedOpts = array_map('trim', explode(',', $rawGiven));
                }
            }

            if(empty($correctOptionIds) && !empty($correct)){
                $correctOptionIds = array_map('trim', explode(',', $correct));
            }

            $correctCount = 0;
            $incorrectCount = 0;
            foreach($selectedOpts as $so){
                $soStr = trim((string)$so);
                $isMatch = false;
                foreach($correctOptionIds as $co){
                    if(strcasecmp($soStr, trim((string)$co)) === 0){
                        $isMatch = true; break;
                    }
                }
                if($isMatch) $correctCount++;
                else $incorrectCount++;
            }

            // Automatic zero points if any wrong selection or missed option (exact match required)
            $isCorrect = (count($correctOptionIds) > 0 && $correctCount === count($correctOptionIds) && $incorrectCount === 0);
            $earnedPts = $isCorrect ? $pts : 0.0;
        } elseif($type === 'multiple_choice'){
            $targetOptId = $correctOptionIds[0] ?? '';
            $submittedOptId = is_string($rawGiven) ? trim($rawGiven) : '';

            $parseIdx = function($val, $oList){
                $s = trim((string)$val);
                if(!$s) return -1;
                if(strlen($s) === 1 && ctype_alpha($s)){
                    $idx = ord(strtoupper($s)) - 65;
                    if(isset($oList[$idx])) return $idx;
                }
                if(preg_match('/^([a-zA-Z])[\.\)\:\-\s]+/i', $s, $m)){
                    $idx = ord(strtoupper($m[1])) - 65;
                    if(isset($oList[$idx])) return $idx;
                }
                if(is_numeric($s) && isset($oList[intval($s)])){
                    return intval($s);
                }
                $cleanVal = strtolower(trim(preg_replace('/^[a-zA-Z0-9][\.\)\:\-\s]+/i', '', $s)));
                foreach($oList as $k => $optText){
                    $cleanOpt = strtolower(trim(preg_replace('/^[a-zA-Z0-9][\.\)\:\-\s]+/i', '', $optText)));
                    if(strcasecmp(trim($optText), $s) === 0 || $cleanOpt === $cleanVal || $cleanOpt === strtolower($s) || strtolower($optText) === $cleanVal){
                        return $k;
                    }
                }
                return -1;
            };

            if($targetOptId && strcasecmp($submittedOptId, $targetOptId) === 0){
                $isCorrect = true;
            } elseif(!empty($opts)){
                $correctIdx = $parseIdx($correct, $opts);
                $givenIdx   = $parseIdx($submittedOptId, $opts);
                if($correctIdx >= 0 && $givenIdx >= 0 && $correctIdx === $givenIdx){
                    $isCorrect = true;
                } elseif(strcasecmp($submittedOptId, $correct) === 0 && $submittedOptId !== ''){
                    $isCorrect = true;
                }
            } elseif(strcasecmp($submittedOptId, $correct) === 0 && $submittedOptId !== ''){
                $isCorrect = true;
            }
            $earnedPts = $isCorrect ? $pts : 0;
        } elseif($type === 'true_false'){
            $truthVal = intval($qr['truth_value'] ?? 1);
            $givenStr = strtolower(trim((string)$rawGiven));
            $isGivenTrue = in_array($givenStr, ['true', 't', '1']);
            $isGivenFalse = in_array($givenStr, ['false', 'f', '0']);

            if(($truthVal == 1 && $isGivenTrue) || ($truthVal == 0 && $isGivenFalse)){
                $isCorrect = true; $earnedPts = $pts;
            } elseif(in_array(strtolower($correct), ['true', 't', '1']) && $isGivenTrue){
                $isCorrect = true; $earnedPts = $pts;
            } elseif(in_array(strtolower($correct), ['false', 'f', '0']) && $isGivenFalse){
                $isCorrect = true; $earnedPts = $pts;
            }
        } elseif($type === 'modified_true_false'){
            $truthVal = intval($qr['truth_value'] ?? 1);
            $givenObj = is_array($rawGiven) ? $rawGiven : (is_string($rawGiven) ? json_decode($rawGiven, true) ?: ['truth' => $rawGiven] : []);
            $givenTruth = strtolower(trim($givenObj['truth'] ?? ''));
            $givenCorr  = strtolower(trim($givenObj['correction'] ?? ($givenObj['replacement'] ?? '')));

            if($truthVal == 1){
                if(in_array($givenTruth, ['true', 't', '1'])){ $isCorrect = true; $earnedPts = $pts; }
            } else {
                $halfPts = round($pts / 2.0, 2);
                if(in_array($givenTruth, ['false', 'f', '0'])){
                    $earnedPts += $halfPts;
                    $expectedCorr = strtolower(trim($qr['correct_replacement'] ?? ''));
                    if($expectedCorr && (strpos($givenCorr, $expectedCorr) !== false || levenshtein($givenCorr, $expectedCorr) <= 2)){
                        $earnedPts += ($pts - $halfPts);
                        $isCorrect = true;
                    }
                }
            }
        } elseif($type === 'identification'){
            $givenText = strtolower(trim((string)$rawGiven));
            $correctText = strtolower(trim($correct));
            $accList = array_map('strtolower', array_map('trim', $acceptable));
            $accList[] = $correctText;

            foreach($accList as $acc){
                if($acc !== '' && ($givenText === $acc || levenshtein($givenText, $acc) <= 1)){
                    $isCorrect = true; $earnedPts = $pts;
                    break;
                }
            }
        } elseif($type === 'enumeration'){
            $correctItems = !empty($acceptable) ? $acceptable : array_values(array_filter(array_map('trim', explode(',', strtolower($correct)))));
            $givenItems   = is_array($rawGiven) ? $rawGiven : array_values(array_filter(array_map('trim', explode(',', strtolower((string)$rawGiven)))));
            $matched = 0;
            foreach($correctItems as $ci){
                $ciClean = strtolower(trim($ci));
                foreach($givenItems as $gi){
                    $giClean = strtolower(trim($gi));
                    if($ciClean === $giClean || strpos($giClean, $ciClean) !== false || levenshtein($giClean, $ciClean) <= 1){
                        $matched++;
                        break;
                    }
                }
            }
            $earnedPts = count($correctItems) > 0 ? round($pts * min(1.0, $matched / count($correctItems)), 2) : 0;
            $isCorrect = ($earnedPts >= $pts);
        } else {
            $givenStr = is_string($rawGiven) ? trim($rawGiven) : (is_array($rawGiven) ? json_encode($rawGiven) : '');
            $isCorrect = (strcasecmp($givenStr, $correct) === 0 && $givenStr !== '');
            $earnedPts = $isCorrect ? $pts : 0;
        }

        return [
            'id'                => $qId,
            'question_text'     => $qr['question_text'],
            'topic'             => $qr['topic'] ?: 'General',
            'question_type'     => $type,
            'options'           => $opts,
            'options_data'      => $optsData,
            'correct_option_ids'=> $correctOptionIds,
            'matching_pairs'    => $mPairs,
            'correct_answer'    => $correct ?: ($type === 'essay' ? 'Teacher Grading / Rubric' : ''),
            'points'            => $pts,
            'given_answer'      => $rawGiven,
            'is_correct'        => $isCorrect,
            'earned_points'     => $earnedPts,
            'relevance_pct'     => $relevancePct
        ];
    };

    // Get questions with correct answers
    $qs = $conn->query("SELECT * FROM quiz_questions WHERE quiz_id=$actualQuizId OR quiz_id=$quiz_id ORDER BY id");
    $questions = [];
    while($r = $qs->fetch_assoc()){
        $qId = intval($r['id']);
        $rawGiven = $answers[$qId] ?? null;
        $evalRes = $evaluateQuestion($r, $rawGiven, $essayScoresMap, $moduleTopics);
        $questions[] = $evalRes;
    }

    if(ob_get_length()) ob_clean();
    echo json_encode([
        'success'          => true,
        'quiz_id'          => $actualQuizId,
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

// ── Teacher: Save / Edit Student Essay Score ───────────────────────────────
if($action === 'save_essay_score' && $role === 'TEACHER'){
    $quiz_id      = intval($_POST['quiz_id'] ?? $_GET['quiz_id'] ?? 0);
    $student_code = $conn->real_escape_string($_POST['student_code'] ?? $_GET['student_code'] ?? '');
    $question_id  = intval($_POST['question_id'] ?? $_GET['question_id'] ?? 0);
    $new_pts      = floatval($_POST['score'] ?? $_GET['score'] ?? 0);

    if(!$quiz_id || !$student_code || !$question_id){
        echo json_encode(['success'=>false, 'msg'=>'Missing required parameters']);
        exit;
    }

    $subQ = $conn->query("SELECT id, quiz_id, score, total_points, answers, essay_scores FROM quiz_submissions WHERE quiz_id=$quiz_id AND student_code='$student_code' LIMIT 1");
    if(!$subQ || $subQ->num_rows === 0){
        // Try finding submission by linked quiz IDs
        $qz = $conn->query("SELECT title, teacher_code, class_id FROM quizzes WHERE id=$quiz_id LIMIT 1");
        $qInfo = $qz ? $qz->fetch_assoc() : null;
        if($qInfo){
            $qTitle = $conn->real_escape_string($qInfo['title']);
            $tCode  = $conn->real_escape_string($qInfo['teacher_code']);
            $subQ = $conn->query("SELECT s.id, s.quiz_id, s.score, s.total_points, s.answers, s.essay_scores FROM quiz_submissions s JOIN quizzes q ON s.quiz_id=q.id WHERE q.title='$qTitle' AND q.teacher_code='$tCode' AND s.student_code='$student_code' LIMIT 1");
        }
    }

    if(!$subQ || $subQ->num_rows === 0){
        echo json_encode(['success'=>false, 'msg'=>'Student submission not found']);
        exit;
    }

    $sub = $subQ->fetch_assoc();
    $sub_id = intval($sub['id']);
    $actualQuizId = intval($sub['quiz_id']);

    // Check question max points
    $qRow = $conn->query("SELECT points FROM quiz_questions WHERE id=$question_id LIMIT 1");
    $maxPts = ($qRow && $qRow->num_rows > 0) ? floatval($qRow->fetch_assoc()['points']) : 10;
    if($new_pts < 0) $new_pts = 0;
    if($new_pts > $maxPts) $new_pts = $maxPts;

    // Update essay_scores map
    $essayScoresMap = json_decode($sub['essay_scores'] ?? '{}', true) ?: [];
    $essayScoresMap[$question_id] = $new_pts;
    $essayScoresJson = $conn->real_escape_string(json_encode($essayScoresMap));

    // Get class module topics to test essay connection if needed
    $classId = intval($quiz['class_id'] ?? 0);
    $moduleTopics = [];
    if($classId > 0){
        $mtq = $conn->query("SELECT title, topic, description FROM class_modules WHERE class_id=$classId");
        if($mtq){
            while($mr = $mtq->fetch_assoc()){
                if(!empty($mr['topic'])) $moduleTopics[] = trim($mr['topic']);
                if(!empty($mr['title'])) $moduleTopics[] = trim($mr['title']);
            }
        }
    }

    // Recalculate total score across all questions in the quiz using robust multi-type evaluator
    $answers = json_decode($sub['answers'] ?? '{}', true) ?: [];
    $qs = $conn->query("SELECT * FROM quiz_questions WHERE quiz_id=$actualQuizId OR quiz_id=$quiz_id");
    $totalScore = 0;
    $totalPoints = 0;

    while($qr = $qs->fetch_assoc()){
        $qId = intval($qr['id']);
        $rawGiven = $answers[$qId] ?? null;
        $evalRes = $evaluateQuestion($qr, $rawGiven, $essayScoresMap, $moduleTopics);
        $totalScore += $evalRes['earned_points'];
        $totalPoints += floatval($qr['points'] ?? 1);
    }

    $totalScore = round($totalScore, 2);
    $conn->query("UPDATE quiz_submissions SET score=$totalScore, essay_scores='$essayScoresJson' WHERE id=$sub_id");

    // Sync to class_record_scores
    $qzInfo = $conn->query("SELECT class_id FROM quizzes WHERE id=$actualQuizId LIMIT 1");
    $cId = $qzInfo && $qzInfo->num_rows > 0 ? intval($qzInfo->fetch_assoc()['class_id']) : 0;
    if($cId > 0){
        $syncQ = $conn->query("SELECT id, max_score FROM class_record_columns WHERE quiz_id IN ($actualQuizId, $quiz_id) AND class_id=$cId LIMIT 1");
        if($syncQ && $syncQ->num_rows > 0){
            $sRow = $syncQ->fetch_assoc();
            $colId = intval($sRow['id']);
            $maxScore = floatval($sRow['max_score']);
            $scaled = ($totalPoints > 0 && $maxScore > 0) ? round(($totalScore / $totalPoints) * $maxScore, 2) : $totalScore;
            $conn->query("INSERT INTO class_record_scores (column_id, class_id, student_code, score)
                          VALUES ($colId, $cId, '$student_code', $scaled)
                          ON DUPLICATE KEY UPDATE score = $scaled");
        }
    }

    $pct = $totalPoints > 0 ? round(($totalScore / $totalPoints) * 100, 1) : 0;

    echo json_encode([
        'success'      => true,
        'msg'          => 'Essay score saved successfully and synced to Class Record!',
        'question_id'  => $question_id,
        'earned_points'=> $new_pts,
        'new_score'    => $totalScore,
        'total_points' => $totalPoints,
        'percentage'   => $pct
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

// ── Teacher: Allow Student to Retake Quiz (Reset Attempt) ────────────────────
if(($action === 'allow_retake' || $action === 'reset_student_quiz') && ($role === 'TEACHER' || $role === 'ADMIN')){
    $quiz_id = intval($_POST['quiz_id'] ?? $_GET['quiz_id'] ?? 0);
    $student_code = trim($conn->real_escape_string($_POST['student_code'] ?? $_GET['student_code'] ?? ''));

    if(!$quiz_id || empty($student_code)){
        if(ob_get_length()) ob_clean();
        echo json_encode(['success' => false, 'msg' => 'Quiz ID and Student Code are required']);
        exit;
    }

    $qzQ = $conn->query("SELECT id, title, teacher_code, class_id FROM quizzes WHERE id=$quiz_id");
    if(!$qzQ || $qzQ->num_rows === 0){
        if(ob_get_length()) ob_clean();
        echo json_encode(['success' => false, 'msg' => 'Quiz not found']);
        exit;
    }
    $quizRow = $qzQ->fetch_assoc();
    $qTitle = $conn->real_escape_string($quizRow['title']);
    $tCode  = $conn->real_escape_string($quizRow['teacher_code']);

    if($role === 'TEACHER' && $tCode !== $uc){
        $cOwn = $conn->query("SELECT id FROM classes WHERE id=".intval($quizRow['class_id'])." AND teacher_code='$uc'");
        if(!$cOwn || $cOwn->num_rows === 0){
            if(ob_get_length()) ob_clean();
            echo json_encode(['success' => false, 'msg' => 'Unauthorized']);
            exit;
        }
    }

    $linkedIds = [$quiz_id];
    $lq = $conn->query("SELECT id FROM quizzes WHERE title='$qTitle' AND teacher_code='$tCode'");
    if($lq){
        while($lr = $lq->fetch_assoc()){
            $linkedIds[] = intval($lr['id']);
        }
    }
    $linkedIdsList = implode(',', array_unique($linkedIds));

    // 1. Delete quiz submission
    $conn->query("DELETE FROM quiz_submissions WHERE quiz_id IN ($linkedIdsList) AND student_code='$student_code'");

    // 2. Delete / reset quiz attempts
    $conn->query("DELETE FROM quiz_attempts WHERE quiz_id IN ($linkedIdsList) AND student_code='$student_code'");

    // 3. Clear score from class record
    $conn->query("DELETE crs FROM class_record_scores crs
                  JOIN class_record_columns crc ON crs.column_id = crc.id
                  WHERE crc.quiz_id IN ($linkedIdsList) AND crs.student_code='$student_code'");

    if(ob_get_length()) ob_clean();
    echo json_encode([
        'success' => true,
        'msg' => "Quiz attempt reset successfully. The student ($student_code) is now allowed to retake the quiz."
    ]);
    exit;
}

echo json_encode(['success'=>false,'msg'=>'Invalid action']);
