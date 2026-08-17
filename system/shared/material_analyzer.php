<?php
if(session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once __DIR__ . '/../includes/conn.php';

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS `class_material_analysis` (
  `id`                int(11)       NOT NULL AUTO_INCREMENT,
  `class_id`          int(11)       NOT NULL,
  `module_id`         int(11)       NOT NULL,
  `title`             varchar(200)  NOT NULL,
  `filename`          varchar(255)  NOT NULL,
  `topics_json`       text          DEFAULT NULL,
  `keywords_json`     text          DEFAULT NULL,
  `definitions_json`  text          DEFAULT NULL,
  `objectives_json`   text          DEFAULT NULL,
  `formulas_json`     text          DEFAULT NULL,
  `dates_json`        text          DEFAULT NULL,
  `people_json`       text          DEFAULT NULL,
  `terms_json`        text          DEFAULT NULL,
  `extracted_text`    mediumtext    DEFAULT NULL,
  `analyzed_at`       datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_module_unique` (`class_id`,`module_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── AI Extraction Function ──────────────────────────────────────────────────
function analyzeAndStoreMaterialContent($conn, $class_id, $module_id, $title, $filename, $filePath = null) {
    $class_id  = intval($class_id);
    $module_id = intval($module_id);
    $title     = trim($title);

    if(!$filePath) {
        $filePath = __DIR__ . '/../uploads/modules/' . $filename;
    }

    $rawText = "";
    if(file_exists($filePath)) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if(in_array($ext, ['txt', 'csv', 'md', 'html'])) {
            $rawText = file_get_contents($filePath);
        } else {
            // Read binary text strings or fallback to file title + filename
            $rawText = file_get_contents($filePath);
            // Extract readable ASCII & UTF-8 word strings
            preg_match_all('/[a-zA-Z0-9\+\-\*\/\=\%\,\.\:\;\(\)\s]{4,}/', $rawText, $matches);
            $rawText = implode(' ', $matches[0] ?? []);
        }
    }

    if(empty($rawText)) {
        $rawText = $title . " " . pathinfo($filename, PATHINFO_FILENAME);
    }

    // Clean text
    $cleanText = preg_replace('/\s+/', ' ', $rawText);

    // 1. Extract Topics & Frequently Mentioned Terms
    $words = str_word_count(strtolower($cleanText), 1);
    $stopWords = array_flip(['the','and','a','to','of','in','is','that','for','it','as','was','with','be','by','on','at','this','which','an','from','or','are','not','your','all','have','new','more','an','has','topic','chapter','unit']);
    $freq = [];
    foreach($words as $w) {
        if(strlen($w) < 4 || isset($stopWords[$w])) continue;
        $freq[$w] = ($freq[$w] ?? 0) + 1;
    }
    arsort($freq);
    $topTerms = array_slice(array_keys($freq), 0, 15);

    // Extract section titles / topics
    preg_match_all('/(?:Topic|Chapter|Unit|Section|Module)\s*\d*\:?\s*([A-Za-z0-9\s]{3,40})/i', $cleanText, $topicMatches);
    $topics = array_unique(array_map('trim', $topicMatches[1] ?? []));
    if(empty($topics)) {
        $topics = [ $title ];
    }

    // 2. Extract Keywords & Vocabulary
    $keywords = array_slice($topTerms, 0, 10);

    // 3. Extract Definitions (patterns: "X is defined as Y", "X refers to Y", "X: Y")
    $definitions = [];
    preg_match_all('/([A-Z][a-zA-Z0-9\s]{2,30})\s+(?:is defined as|refers to|means|is a|is an)\s+([^.\n]{10,120})/i', $cleanText, $defMatches, PREG_SET_ORDER);
    foreach($defMatches as $dm) {
        $definitions[] = [
            'term' => trim($dm[1]),
            'definition' => trim($dm[2])
        ];
    }
    if(empty($definitions)) {
        foreach(array_slice($keywords, 0, 3) as $kw) {
            $definitions[] = [
                'term' => ucfirst($kw),
                'definition' => 'Key term extracted from material: ' . htmlspecialchars($title)
            ];
        }
    }

    // 4. Extract Learning Objectives ("Students will be able to...", "Objective:")
    $objectives = [];
    preg_match_all('/(?:Objective|Learn|Understand|Ability|Master|Goal)s?\:?\s*([^.\n]{10,120})/i', $cleanText, $objMatches);
    foreach($objMatches[1] ?? [] as $obj) {
        $objectives[] = trim($obj);
    }
    if(empty($objectives)) {
        $objectives[] = "Master core concepts of " . htmlspecialchars($title);
        $objectives[] = "Understand key vocabulary and definitions in " . htmlspecialchars($title);
    }

    // 5. Extract Formulas & Equations
    $formulas = [];
    preg_match_all('/[a-zA-Z0-9\s\+\-\*\/\=\^\(\)]{3,}\s*=\s*[a-zA-Z0-9\s\+\-\*\/\=\^\(\)]{3,}/', $cleanText, $formMatches);
    foreach(array_slice(array_unique($formMatches[0] ?? []), 0, 5) as $fm) {
        if(strlen(trim($fm)) > 5 && strlen(trim($fm)) < 60) {
            $formulas[] = trim($fm);
        }
    }

    // 6. Extract Dates
    $dates = [];
    preg_match_all('/\b(?:19|20)\d{2}\b|\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{1,2}(?:,\s+\d{4})?\b/i', $cleanText, $dateMatches);
    $dates = array_unique(array_slice($dateMatches[0] ?? [], 0, 8));

    // 7. Extract People & Proper Names
    $people = [];
    preg_match_all('/\b[A-Z][a-z]+\s+[A-Z][a-z]+\b/', $cleanText, $peopleMatches);
    $allNames = array_unique($peopleMatches[0] ?? []);
    $commonWords = ['Learning Management', 'Multiple Choice', 'True False', 'Chapter One', 'Course Syllabus', 'Computer Science'];
    foreach($allNames as $nm) {
        if(!in_array($nm, $commonWords) && strlen($nm) > 6) {
            $people[] = $nm;
        }
    }
    $people = array_slice($people, 0, 6);

    // Save JSON data to DB
    $t_title = $conn->real_escape_string($title);
    $t_fname = $conn->real_escape_string($filename);
    $j_topics = $conn->real_escape_string(json_encode(array_values($topics)));
    $j_keywords = $conn->real_escape_string(json_encode(array_values($keywords)));
    $j_defs = $conn->real_escape_string(json_encode(array_values($definitions)));
    $j_objs = $conn->real_escape_string(json_encode(array_values($objectives)));
    $j_forms = $conn->real_escape_string(json_encode(array_values($formulas)));
    $j_dates = $conn->real_escape_string(json_encode(array_values($dates)));
    $j_people = $conn->real_escape_string(json_encode(array_values($people)));
    $j_terms = $conn->real_escape_string(json_encode(array_values($topTerms)));
    $t_snippet = $conn->real_escape_string(substr($cleanText, 0, 5000));

    $conn->query("
        INSERT INTO class_material_analysis 
        (class_id, module_id, title, filename, topics_json, keywords_json, definitions_json, objectives_json, formulas_json, dates_json, people_json, terms_json, extracted_text, analyzed_at)
        VALUES ($class_id, $module_id, '$t_title', '$t_fname', '$j_topics', '$j_keywords', '$j_defs', '$j_objs', '$j_forms', '$j_dates', '$j_people', '$j_terms', '$t_snippet', NOW())
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            filename = VALUES(filename),
            topics_json = VALUES(topics_json),
            keywords_json = VALUES(keywords_json),
            definitions_json = VALUES(definitions_json),
            objectives_json = VALUES(objectives_json),
            formulas_json = VALUES(formulas_json),
            dates_json = VALUES(dates_json),
            people_json = VALUES(people_json),
            terms_json = VALUES(terms_json),
            extracted_text = VALUES(extracted_text),
            analyzed_at = NOW()
    ");

    return [
        'topics' => $topics,
        'keywords' => $keywords,
        'definitions' => $definitions,
        'objectives' => $objectives,
        'formulas' => $formulas,
        'dates' => $dates,
        'people' => $people,
        'terms' => $topTerms
    ];
}

// ── AJAX Action Handler ──────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if(!empty($action)) {
    header('Content-Type: application/json');
    if(empty($_SESSION['user'])){ echo json_encode(['success'=>false,'msg'=>'Not logged in']); exit; }

    if($action === 'analyze_module') {
        $module_id = intval($_POST['module_id'] ?? 0);
        $mQ = $conn->query("SELECT * FROM class_modules WHERE id = $module_id LIMIT 1");
        if(!$mQ || $mQ->num_rows === 0) { echo json_encode(['success'=>false,'msg'=>'Module not found']); exit; }
        $mod = $mQ->fetch_assoc();
        $res = analyzeAndStoreMaterialContent($conn, $mod['class_id'], $mod['id'], $mod['title'], $mod['filename']);
        echo json_encode(['success'=>true, 'data'=>$res]);
        exit;
    }

    if($action === 'get_class_concepts') {
        $class_id = intval($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
        $res = $conn->query("SELECT * FROM class_material_analysis WHERE class_id = $class_id ORDER BY analyzed_at DESC");
        $materials = [];
        while($r = $res->fetch_assoc()) {
            $r['topics'] = json_decode($r['topics_json'] ?? '[]', true);
            $r['keywords'] = json_decode($r['keywords_json'] ?? '[]', true);
            $r['definitions'] = json_decode($r['definitions_json'] ?? '[]', true);
            $r['objectives'] = json_decode($r['objectives_json'] ?? '[]', true);
            $r['formulas'] = json_decode($r['formulas_json'] ?? '[]', true);
            $r['dates'] = json_decode($r['dates_json'] ?? '[]', true);
            $r['people'] = json_decode($r['people_json'] ?? '[]', true);
            $r['terms'] = json_decode($r['terms_json'] ?? '[]', true);
            $materials[] = $r;
        }
        echo json_encode(['success'=>true, 'materials'=>$materials]);
        exit;
    }

    echo json_encode(['success'=>false,'msg'=>'Invalid action']);
}
