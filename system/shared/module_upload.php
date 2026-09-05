<?php
session_start();
ob_start(); // Buffer output to prevent unexpected warnings from corrupting JSON output
include '../includes/conn.php';
header('Content-Type: application/json');

if (empty($_SESSION['user'])) {
    ob_end_clean();
    echo json_encode(['success' => false, 'msg' => 'Not logged in']);
    exit;
}

$user = $_SESSION['user'];
$role = strtoupper($user['role'] ?? $user['user_group'] ?? '');

if (!in_array($role, ['TEACHER', 'ADMIN', 'SUPERADMIN', 'STUDENT'])) {
    ob_end_clean();
    echo json_encode(['success' => false, 'msg' => 'Unauthorized']);
    exit;
}

// Ensure class_modules table exists
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

safeAddColumns($conn, 'class_modules', [
    'topic'     => 'varchar(200) DEFAULT NULL AFTER `original_name`',
    'folder_id' => 'int(11) DEFAULT NULL AFTER `class_id`'
]);

$class_id  = intval($_POST['class_id'] ?? 0);
$title     = trim($_POST['title'] ?? '');
$topic     = trim($_POST['topic'] ?? '');
$folder_id = !empty($_POST['folder_id']) ? intval($_POST['folder_id']) : null;
$folderSql = $folder_id ? "$folder_id" : "NULL";

if (!$class_id || !$title) {
    ob_end_clean();
    echo json_encode(['success' => false, 'msg' => 'Class and title are required']);
    exit;
}

// Verify authorization
$tc = $conn->real_escape_string($user['user_code']);
if (!in_array($role, ['ADMIN', 'SUPERADMIN'])) {
    $q = $conn->query("SELECT id FROM classes WHERE id=$class_id AND (teacher_code='$tc' OR EXISTS (SELECT 1 FROM class_members WHERE class_id=$class_id AND user_code='$tc'))");
    if (!$q || $q->num_rows === 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'msg' => 'Unauthorized for this class']);
        exit;
    }
}

// Check PHP file upload errors
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $errMsgs = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize limit in php.ini',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE limit',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE    => 'No file was selected for upload',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk. Check folder permissions.',
        UPLOAD_ERR_EXTENSION  => 'PHP extension stopped file upload'
    ];
    $errMsg = $errMsgs[$errCode] ?? 'File upload error occurred';
    ob_end_clean();
    echo json_encode(['success' => false, 'msg' => $errMsg]);
    exit;
}

$allowed = ['pdf','doc','docx','ppt','pptx','xls','xlsx','txt','csv','zip','rar','png','jpg','jpeg','gif','webp','svg','mp4','webm','mp3','wav'];
$orig    = $_FILES['file']['name'];
$ext     = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

if (!in_array($ext, $allowed)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'msg' => "File type (.$ext) is not allowed"]);
    exit;
}

// Max 50MB
if ($_FILES['file']['size'] > 50 * 1024 * 1024) {
    ob_end_clean();
    echo json_encode(['success' => false, 'msg' => 'File too large (max 50MB)']);
    exit;
}

$filename  = uniqid('mod_') . '.' . $ext;
$uploadDir = __DIR__ . '/../uploads/modules/';

if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}

$dest = $uploadDir . $filename;

if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'msg' => 'Upload failed. Check permissions on uploads/modules/ folder']);
    exit;
}

$t             = $conn->real_escape_string($title);
$fn            = $conn->real_escape_string($filename);
$on            = $conn->real_escape_string($orig);
$sz            = intval($_FILES['file']['size']);
$topic_escaped = $conn->real_escape_string($topic);

$conn->query("INSERT INTO class_modules (class_id, folder_id, uploaded_by, title, filename, original_name, file_size, topic)
              VALUES ($class_id, $folderSql, '$tc', '$t', '$fn', '$on', $sz, '$topic_escaped')");
$module_id = $conn->insert_id;

// Background tasks wrapped safely to avoid breaking file upload
try {
    if (file_exists(__DIR__ . '/material_analyzer.php')) {
        require_once __DIR__ . '/material_analyzer.php';
        if (function_exists('analyzeAndStoreMaterialContent')) {
            analyzeAndStoreMaterialContent($conn, $class_id, $module_id, $t, $fn, $dest);
        }
    }
} catch (\Throwable $e) {
    error_log("Material Analyzer Warning: " . $e->getMessage());
}

ob_end_clean();
echo json_encode(['success' => true, 'msg' => 'Module uploaded successfully']);
exit;
