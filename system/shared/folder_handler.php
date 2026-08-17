<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include_once __DIR__ . '/../includes/conn.php';
header('Content-Type: application/json');

if (empty($_SESSION['user'])) {
    echo json_encode(['success' => false, 'msg' => 'Not logged in']);
    exit;
}

$user = $_SESSION['user'];
$uc   = $conn->real_escape_string($user['user_code']);
$role = strtoupper($user['role'] ?? $user['user_group'] ?? '');
$isTeacher = in_array($role, ['TEACHER', 'ADMIN', 'SUPERADMIN']);

// Ensure folders table exists
$conn->query("CREATE TABLE IF NOT EXISTS `class_material_folders` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `class_id` int(11) NOT NULL,
    `name` varchar(150) NOT NULL,
    `folder_type` varchar(50) NOT NULL DEFAULT 'student_ppt',
    `description` text DEFAULT NULL,
    `is_shared` tinyint(1) NOT NULL DEFAULT 1,
    `allow_student_view` tinyint(1) NOT NULL DEFAULT 1,
    `created_by` varchar(50) NOT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `class_id` (`class_id`),
    KEY `folder_type` (`folder_type`),
    KEY `is_shared` (`is_shared`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure class_modules has folder_id
safeAddColumns($conn, 'class_modules', [
    'folder_id' => 'int(11) DEFAULT NULL AFTER `class_id`'
]);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Create Folder ──────────────────────────────────────────────────────────
if ($action === 'create_folder') {
    if (!$isTeacher) {
        echo json_encode(['success' => false, 'msg' => 'Unauthorized']);
        exit;
    }

    $class_id    = intval($_POST['class_id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $folder_type = trim($_POST['folder_type'] ?? 'student_ppt');
    $description = trim($_POST['description'] ?? '');
    $allow_view  = isset($_POST['allow_student_view']) ? intval($_POST['allow_student_view']) : 1;

    if (!$class_id || empty($name)) {
        echo json_encode(['success' => false, 'msg' => 'Class ID and Folder Name are required']);
        exit;
    }

    // Verify teacher owns class or is admin
    if (!in_array($role, ['ADMIN', 'SUPERADMIN'])) {
        $chk = $conn->query("SELECT id FROM classes WHERE id=$class_id AND teacher_code='$uc'");
        if (!$chk || $chk->num_rows === 0) {
            echo json_encode(['success' => false, 'msg' => 'Unauthorized for this class']);
            exit;
        }
    }

    $nameEsc = $conn->real_escape_string($name);
    $typeEsc = $conn->real_escape_string($folder_type);
    $descEsc = $conn->real_escape_string($description);

    $ins = $conn->query("INSERT INTO class_material_folders 
        (class_id, name, folder_type, description, is_shared, allow_student_view, created_by)
        VALUES ($class_id, '$nameEsc', '$typeEsc', '$descEsc', $allow_view, $allow_view, '$uc')");

    if ($ins) {
        $folder_id = $conn->insert_id;
        echo json_encode([
            'success'   => true,
            'msg'       => 'Folder created successfully!',
            'folder_id' => $folder_id,
            'name'      => $name
        ]);
    } else {
        echo json_encode(['success' => false, 'msg' => 'Database error: ' . $conn->error]);
    }
    exit;
}

// ── Edit Folder ────────────────────────────────────────────────────────────
if ($action === 'edit_folder') {
    if (!$isTeacher) {
        echo json_encode(['success' => false, 'msg' => 'Unauthorized']);
        exit;
    }

    $folder_id   = intval($_POST['folder_id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $folder_type = trim($_POST['folder_type'] ?? 'student_ppt');
    $description = trim($_POST['description'] ?? '');
    $allow_view  = isset($_POST['allow_student_view']) ? intval($_POST['allow_student_view']) : 1;

    if (!$folder_id || empty($name)) {
        echo json_encode(['success' => false, 'msg' => 'Folder ID and Name required']);
        exit;
    }

    $nameEsc = $conn->real_escape_string($name);
    $typeEsc = $conn->real_escape_string($folder_type);
    $descEsc = $conn->real_escape_string($description);

    $upd = $conn->query("UPDATE class_material_folders 
        SET name='$nameEsc', folder_type='$typeEsc', description='$descEsc', is_shared=$allow_view, allow_student_view=$allow_view 
        WHERE id=$folder_id");

    if ($upd) {
        echo json_encode(['success' => true, 'msg' => 'Folder updated successfully!']);
    } else {
        echo json_encode(['success' => false, 'msg' => 'Failed to update folder']);
    }
    exit;
}

// ── Toggle Student View Permission (Send to / Hide from Students) ──────────
if ($action === 'toggle_student_view') {
    if (!$isTeacher) {
        echo json_encode(['success' => false, 'msg' => 'Unauthorized']);
        exit;
    }

    $folder_id = intval($_POST['folder_id'] ?? 0);
    if (!$folder_id) {
        echo json_encode(['success' => false, 'msg' => 'Folder ID required']);
        exit;
    }

    $fQ = $conn->query("SELECT allow_student_view FROM class_material_folders WHERE id=$folder_id");
    if (!$fQ || $fQ->num_rows === 0) {
        echo json_encode(['success' => false, 'msg' => 'Folder not found']);
        exit;
    }
    $f = $fQ->fetch_assoc();
    $newStatus = $f['allow_student_view'] ? 0 : 1;

    $conn->query("UPDATE class_material_folders SET allow_student_view=$newStatus, is_shared=$newStatus WHERE id=$folder_id");

    echo json_encode([
        'success' => true,
        'msg'     => $newStatus ? 'Folder sent & shared to students (Allowed to view)!' : 'Folder hidden from students (Teacher only).',
        'allowed' => $newStatus
    ]);
    exit;
}

// ── Delete Folder ──────────────────────────────────────────────────────────
if ($action === 'delete_folder') {
    if (!$isTeacher) {
        echo json_encode(['success' => false, 'msg' => 'Unauthorized']);
        exit;
    }

    $folder_id = intval($_POST['folder_id'] ?? 0);
    $delete_files = !empty($_POST['delete_files']); // whether to delete enclosed modules too

    if (!$folder_id) {
        echo json_encode(['success' => false, 'msg' => 'Folder ID required']);
        exit;
    }

    if ($delete_files) {
        // Delete all physical files first
        $mQ = $conn->query("SELECT filename FROM class_modules WHERE folder_id=$folder_id");
        if ($mQ) {
            while ($m = $mQ->fetch_assoc()) {
                $path = __DIR__ . '/../uploads/modules/' . $m['filename'];
                if (file_exists($path)) @unlink($path);
            }
        }
        $conn->query("DELETE FROM class_modules WHERE folder_id=$folder_id");
    } else {
        // Move files to root
        $conn->query("UPDATE class_modules SET folder_id=NULL WHERE folder_id=$folder_id");
    }

    $conn->query("DELETE FROM class_material_folders WHERE id=$folder_id");

    echo json_encode(['success' => true, 'msg' => 'Folder deleted successfully!']);
    exit;
}

// ── Move Module to Folder ──────────────────────────────────────────────────
if ($action === 'move_module') {
    if (!$isTeacher) {
        echo json_encode(['success' => false, 'msg' => 'Unauthorized']);
        exit;
    }

    $module_id = intval($_POST['module_id'] ?? 0);
    $folder_id = !empty($_POST['folder_id']) ? intval($_POST['folder_id']) : 'NULL';

    if (!$module_id) {
        echo json_encode(['success' => false, 'msg' => 'Module ID required']);
        exit;
    }

    $conn->query("UPDATE class_modules SET folder_id=$folder_id WHERE id=$module_id");

    echo json_encode(['success' => true, 'msg' => 'Module moved successfully!']);
    exit;
}

// ── Get Class Folders ──────────────────────────────────────────────────────
if ($action === 'get_folders') {
    $class_id = intval($_GET['class_id'] ?? 0);
    if (!$class_id) {
        echo json_encode(['success' => false, 'msg' => 'Class ID required']);
        exit;
    }

    $filter = $isTeacher ? "" : " AND allow_student_view = 1";
    $fQ = $conn->query("SELECT f.*, 
        (SELECT COUNT(*) FROM class_modules WHERE folder_id=f.id) AS file_count,
        (SELECT COUNT(*) FROM class_modules WHERE folder_id=f.id AND (original_name LIKE '%.ppt' OR original_name LIKE '%.pptx')) AS ppt_count
        FROM class_material_folders f 
        WHERE f.class_id=$class_id $filter 
        ORDER BY f.created_at ASC");

    $folders = [];
    if ($fQ) {
        while ($r = $fQ->fetch_assoc()) {
            $folders[] = [
                'id'                 => intval($r['id']),
                'name'               => $r['name'],
                'folder_type'        => $r['folder_type'],
                'description'        => $r['description'],
                'allow_student_view' => intval($r['allow_student_view']),
                'file_count'         => intval($r['file_count']),
                'ppt_count'          => intval($r['ppt_count']),
                'created_at'         => $r['created_at']
            ];
        }
    }

    echo json_encode(['success' => true, 'folders' => $folders]);
    exit;
}

echo json_encode(['success' => false, 'msg' => 'Unknown action']);
exit;
