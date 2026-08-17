<?php
if(session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../includes/conn.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if(!empty($action)) {
    header('Content-Type: application/json');
    if(empty($_SESSION['user'])){
        echo json_encode(['success'=>false,'msg'=>'Not logged in']); exit;
    }
}

$user = $_SESSION['user'] ?? null;
$uc   = $user ? $conn->real_escape_string($user['user_code']) : '';
$role = $user ? strtoupper($user['user_group']) : '';

// Ensure required tables exist
$conn->query("CREATE TABLE IF NOT EXISTS `class_modules` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `class_id` int(11) NOT NULL,
    `uploaded_by` varchar(50) NOT NULL,
    `title` varchar(200) NOT NULL,
    `filename` varchar(255) NOT NULL,
    `original_name` varchar(255) NOT NULL,
    `file_size` int(11) DEFAULT 0,
    `topic` varchar(200) DEFAULT NULL,
    `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `class_id` (`class_id`),
    KEY `topic` (`topic`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS `material_repository` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `teacher_code` varchar(50) NOT NULL,
    `title` varchar(200) NOT NULL,
    `filename` varchar(255) NOT NULL,
    `original_name` varchar(255) NOT NULL,
    `file_size` int(11) DEFAULT 0,
    `file_hash` varchar(64) NOT NULL,
    `topic` varchar(200) DEFAULT NULL,
    `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `teacher_code` (`teacher_code`),
    UNIQUE KEY `teacher_hash` (`teacher_code`,`file_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS `class_module_links` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `class_id` int(11) NOT NULL,
    `repo_id` int(11) NOT NULL,
    `linked_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `class_repo` (`class_id`,`repo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS `class_syllabus` (
  `id`            int(11)       NOT NULL AUTO_INCREMENT,
  `class_id`      int(11)       NOT NULL,
  `teacher_code`  varchar(50)   NOT NULL,
  `title`         varchar(200)  NOT NULL,
  `filename`      varchar(255)  NOT NULL,
  `original_name` varchar(255)  NOT NULL,
  `file_size`     int(11)       DEFAULT 0,
  `file_hash`     varchar(64)   DEFAULT NULL,
  `topic`         varchar(200)  DEFAULT NULL,
  `term`          varchar(20)   NOT NULL DEFAULT 'midterm',
  `is_sent`       tinyint(1)    DEFAULT 1,
  `created_at`    datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`       datetime      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_class_id` (`class_id`),
  KEY `idx_topic` (`topic`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

safeAddColumns($conn, 'class_syllabus', [
    'file_hash' => 'varchar(64) DEFAULT NULL',
    'topic'     => 'varchar(200) DEFAULT NULL',
    'term'      => "varchar(20) NOT NULL DEFAULT 'midterm'",
    'is_sent'   => 'tinyint(1) DEFAULT 1',
    'sent_at'   => 'datetime DEFAULT NULL'
]);

// Helper function to auto-sync class materials into class_syllabus without duplicates
function syncClassMaterialsToSyllabus($conn, $class_id, $teacher_code) {
    $addedCount = 0;
    
    // 1. Fetch all class_modules for this class
    $modQ = $conn->query("SELECT * FROM class_modules WHERE class_id = $class_id");
    if($modQ) {
        while($m = $modQ->fetch_assoc()) {
            $title = $conn->real_escape_string($m['title']);
            $filename = $conn->real_escape_string($m['filename']);
            $origName = $conn->real_escape_string($m['original_name']);
            $fileSize = intval($m['file_size']);
            $topic = $conn->real_escape_string($m['topic'] ?? 'General Modules');
            if(empty($topic)) $topic = 'General Modules';
            
            // Deduplication Check: check if entry with same class_id, original_name and file_size exists
            $checkQ = $conn->query("SELECT id FROM class_syllabus WHERE class_id = $class_id AND original_name = '$origName' AND file_size = $fileSize LIMIT 1");
            if($checkQ && $checkQ->num_rows === 0) {
                $conn->query("INSERT INTO class_syllabus (class_id, teacher_code, title, filename, original_name, file_size, topic, term, is_sent, created_at, sent_at)
                              VALUES ($class_id, '$teacher_code', '$title', '$filename', '$origName', $fileSize, '$topic', 'midterm', 1, NOW(), NOW())");
                $addedCount++;
            }
        }
    }

    // 2. Fetch all linked repository materials for this class
    $repoQ = $conn->query("
        SELECT mr.* 
        FROM class_module_links cml
        JOIN material_repository mr ON cml.repo_id = mr.id
        WHERE cml.class_id = $class_id
    ");
    if($repoQ) {
        while($r = $repoQ->fetch_assoc()) {
            $title = $conn->real_escape_string($r['title']);
            $filename = $conn->real_escape_string($r['filename']);
            $origName = $conn->real_escape_string($r['original_name']);
            $fileSize = intval($r['file_size']);
            $fileHash = $conn->real_escape_string($r['file_hash'] ?? '');
            $topic = $conn->real_escape_string($r['topic'] ?? 'Repository Materials');
            if(empty($topic)) $topic = 'Repository Materials';

            // Deduplication Check: check by file_hash or original_name + file_size
            $checkCond = $fileHash ? "file_hash = '$fileHash'" : "(original_name = '$origName' AND file_size = $fileSize)";
            $checkQ = $conn->query("SELECT id FROM class_syllabus WHERE class_id = $class_id AND $checkCond LIMIT 1");
            if($checkQ && $checkQ->num_rows === 0) {
                $conn->query("INSERT INTO class_syllabus (class_id, teacher_code, title, filename, original_name, file_size, file_hash, topic, term, is_sent, created_at, sent_at)
                              VALUES ($class_id, '$teacher_code', '$title', '$filename', '$origName', $fileSize, '$fileHash', '$topic', 'midterm', 1, NOW(), NOW())");
                $addedCount++;
            }
        }
    }

    return $addedCount;
}

// ── Teacher: Trigger Auto Sync ─────────────────────────────────────────────
if($action === 'auto_sync' && $role === 'TEACHER'){
    $class_id = intval($_POST['class_id'] ?? 0);
    if(!$class_id){ echo json_encode(['success'=>false,'msg'=>'Class ID required']); exit; }

    // Verify teacher owns class
    $chk = $conn->query("SELECT id FROM classes WHERE id=$class_id AND teacher_code='$uc'");
    if($chk->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Unauthorized']); exit; }

    $synced = syncClassMaterialsToSyllabus($conn, $class_id, $uc);
    echo json_encode(['success'=>true,'msg'=>"Syllabus auto-synced! ($synced new material".($synced!==1?'s':'')." added, duplicate files skipped).",'new_items'=>$synced]);
    exit;
}

// ── Get Syllabus Items (Teacher / Student) ─────────────────────────────────
if($action === 'get_syllabus'){
    $class_id = intval($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
    if(!$class_id){ echo json_encode(['success'=>false,'msg'=>'Class ID required']); exit; }

    if($role === 'STUDENT') {
        // Verify student is enrolled
        $mem = $conn->query("SELECT id FROM class_members WHERE class_id=$class_id AND user_code='$uc'");
        if($mem->num_rows === 0){ echo json_encode(['success'=>false,'msg'=>'Access denied']); exit; }
        $whereExtra = "AND s.is_sent = 1";
    } else {
        // Auto sync first for teacher
        syncClassMaterialsToSyllabus($conn, $class_id, $uc);
        $whereExtra = "";
    }

    $sql = "
        SELECT s.*, c.class_name, c.subject
        FROM class_syllabus s
        JOIN classes c ON s.class_id = c.id
        WHERE s.class_id = $class_id $whereExtra
        ORDER BY s.topic ASC, s.created_at DESC
    ";
    $res = $conn->query($sql);
    $items = [];
    $topics = [];

    while($r = $res->fetch_assoc()){
        $t = trim($r['topic'] ?: 'General Materials');
        if(!isset($topics[$t])) $topics[$t] = [];
        $topics[$t][] = $r;
        $items[] = $r;
    }

    echo json_encode([
        'success' => true,
        'count' => count($items),
        'topics' => $topics,
        'items' => $items
    ]);
    exit;
}

// ── Teacher: Toggle Published / Sent Status ────────────────────────────────
if($action === 'toggle_sent' && $role === 'TEACHER'){
    $id = intval($_POST['id'] ?? 0);
    $val = intval($_POST['is_sent'] ?? 0);

    $conn->query("UPDATE class_syllabus SET is_sent = $val, sent_at = ".($val ? 'NOW()' : 'NULL')." WHERE id = $id AND teacher_code = '$uc'");
    echo json_encode(['success'=>true,'msg'=>'Status updated']);
    exit;
}

// ── Teacher: Delete Syllabus Item ──────────────────────────────────────────
if($action === 'delete' && $role === 'TEACHER'){
    $id = intval($_POST['id'] ?? 0);
    $conn->query("DELETE FROM class_syllabus WHERE id = $id AND teacher_code = '$uc'");
    echo json_encode(['success'=>true,'msg'=>'Item removed from syllabus']);
    exit;
}

if(!empty($action)) {
    echo json_encode(['success'=>false,'msg'=>'Invalid action']);
}

