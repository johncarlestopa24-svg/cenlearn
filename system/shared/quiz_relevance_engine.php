<?php
if(session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once __DIR__ . '/../includes/conn.php';
include_once __DIR__ . '/material_analyzer.php';

function evaluateQuizRelevanceAndQuality($conn, $quiz_id, $class_id = 0, $questionsOverride = null) {
    $quiz_id  = intval($quiz_id);
    $class_id = intval($class_id);

    // Fetch quiz info if not provided
    if($quiz_id > 0 && !$class_id) {
        $qRes = $conn->query("SELECT class_id, title FROM quizzes WHERE id = $quiz_id LIMIT 1");
        if($qRes && $qRes->num_rows > 0) {
            $qRow = $qRes->fetch_assoc();
            $class_id = intval($qRow['class_id']);
        }
    }

    // Fetch questions
    $questions = [];
    if($questionsOverride !== null) {
        $questions = $questionsOverride;
    } elseif($quiz_id > 0) {
        $qsRes = $conn->query("SELECT * FROM quiz_questions WHERE quiz_id = $quiz_id ORDER BY id ASC");
        if($qsRes) {
            while($r = $qsRes->fetch_assoc()) {
                $r['options'] = json_decode($r['options'] ?? '[]', true);
                $questions[] = $r;
            }
        }
    }

    if(empty($questions)) {
        return [
            'can_publish' => false,
            'relevance_score' => 0,
            'coverage_score' => 0,
            'quality_score' => 0,
            'predicted_pass_rate' => 0,
            'unmatched_questions' => [],
            'untested_topics' => [],
            'recommendations' => ['Add at least one question to evaluate quiz relevance and coverage.'],
            'material_count' => 0
        ];
    }

    // Fetch or trigger material analysis for this class
    $matRes = $conn->query("SELECT * FROM class_material_analysis WHERE class_id = $class_id");
    if((!$matRes || $matRes->num_rows === 0) && $class_id > 0) {
        // Auto-run analysis on any class_modules for this class
        $modQ = $conn->query("SELECT * FROM class_modules WHERE class_id = $class_id");
        if($modQ) {
            while($m = $modQ->fetch_assoc()) {
                analyzeAndStoreMaterialContent($conn, $class_id, $m['id'], $m['title'], $m['filename']);
            }
        }
        $matRes = $conn->query("SELECT * FROM class_material_analysis WHERE class_id = $class_id");
    }

    $materials = [];
    $allTopics = [];
    $allKeywords = [];
    $allObjectives = [];
    $allDefinitions = [];
    $allTerms = [];

    if($matRes) {
        while($m = $matRes->fetch_assoc()) {
            $materials[] = $m;
            $top = json_decode($m['topics_json'] ?? '[]', true);
            $kw  = json_decode($m['keywords_json'] ?? '[]', true);
            $obj = json_decode($m['objectives_json'] ?? '[]', true);
            $def = json_decode($m['definitions_json'] ?? '[]', true);
            $trm = json_decode($m['terms_json'] ?? '[]', true);

            foreach($top as $t) if(trim($t)) $allTopics[trim(strtolower($t))] = trim($t);
            foreach($kw as $k)  if(trim($k)) $allKeywords[trim(strtolower($k))] = trim($k);
            foreach($obj as $o) if(trim($o)) $allObjectives[trim(strtolower($o))] = trim($o);
            foreach($trm as $tr) if(trim($tr)) $allTerms[trim(strtolower($tr))] = trim($tr);
        }
    }

    $masterConceptKeys = array_keys(array_merge($allTopics, $allKeywords, $allTerms));

    // Evaluate each question against material concepts
    $totalQ = count($questions);
    $matchedCount = 0;
    $unmatchedQuestions = [];
    $testedTopics = [];
    $questionTypesCount = [];

    foreach($questions as $index => $q) {
        $qText = strtolower($q['question_text'] ?? '');
        $qTopic = strtolower($q['topic'] ?? '');
        $qOpts = is_array($q['options'] ?? null) ? strtolower(implode(' ', $q['options'])) : strtolower($q['options'] ?? '');
        $combinedText = $qText . ' ' . $qTopic . ' ' . $qOpts;

        $type = $q['question_type'] ?? 'multiple_choice';
        $questionTypesCount[$type] = ($questionTypesCount[$type] ?? 0) + 1;

        $isMatched = false;
        $matchedKeyword = '';

        if(empty($masterConceptKeys)) {
            // If no uploaded materials exist, treat questions matching class subject as aligned
            $isMatched = true;
        } else {
            foreach($masterConceptKeys as $ckey) {
                if(strlen($ckey) >= 3 && strpos($combinedText, $ckey) !== false) {
                    $isMatched = true;
                    $matchedKeyword = $ckey;
                    // Record tested topic
                    if(isset($allTopics[$ckey])) {
                        $testedTopics[$ckey] = true;
                    }
                    break;
                }
            }
        }

        if($isMatched) {
            $matchedCount++;
        } else {
            $unmatchedQuestions[] = [
                'index' => $index + 1,
                'question_text' => $q['question_text'] ?? '',
                'reason' => 'No matching keywords or topics found in uploaded class learning materials.'
            ];
        }
    }

    // 1. Relevance Score
    $relevanceScore = $totalQ > 0 ? round(($matchedCount / $totalQ) * 100, 1) : 0;

    // 2. Coverage Score
    $totalMatTopicsCount = count($allTopics);
    $testedTopicsCount = count($testedTopics);
    $coverageScore = $totalMatTopicsCount > 0 ? round(($testedTopicsCount / $totalMatTopicsCount) * 100, 1) : 100.0;

    $untestedTopics = [];
    foreach($allTopics as $tkey => $tOriginal) {
        if(!isset($testedTopics[$tkey])) {
            $untestedTopics[] = $tOriginal;
        }
    }

    // 3. Quality Rating (1-100)
    $varietyBonus = count($questionTypesCount) > 1 ? 15 : 5;
    $lenScore = 0;
    foreach($questions as $q) {
        if(strlen($q['question_text'] ?? '') > 15) $lenScore += 2;
    }
    $lenAvg = $totalQ > 0 ? min(35, round($lenScore / $totalQ * 10)) : 0;
    $qualityScore = min(100, round(($relevanceScore * 0.5) + $varietyBonus + $lenAvg + 10));

    // 4. Predicted Pass Rate
    $predictedPassRate = min(95, max(45, round(($relevanceScore * 0.6) + ($coverageScore * 0.2) + 15)));

    // 5. Pre-Publish Gating
    $canPublish = ($relevanceScore >= 40 || empty($materials)) && $totalQ > 0;

    // 6. Actionable Recommendations
    $recommendations = [];
    if($relevanceScore < 60 && !empty($materials)) {
        $recommendations[] = "Relevance is below 60%. Align off-topic questions with extracted material keywords.";
    }
    if(!empty($untestedTopics)) {
        $recommendations[] = "Add questions covering untested material topic: '" . $untestedTopics[0] . "' to increase syllabus coverage.";
    }
    if(count($questionTypesCount) === 1) {
        $recommendations[] = "Mix question types (e.g. Identification, True/False) to increase quiz quality rating.";
    }
    if(empty($recommendations)) {
        $recommendations[] = "Quiz is highly aligned with learning materials and ready for publication!";
    }

    return [
        'can_publish' => $canPublish,
        'relevance_score' => $relevanceScore,
        'coverage_score' => $coverageScore,
        'quality_score' => $qualityScore,
        'predicted_pass_rate' => $predictedPassRate,
        'matched_count' => $matchedCount,
        'total_questions' => $totalQ,
        'material_count' => count($materials),
        'unmatched_questions' => $unmatchedQuestions,
        'untested_topics' => array_values($untestedTopics),
        'recommendations' => $recommendations
    ];
}
