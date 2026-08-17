<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include_once __DIR__ . '/../includes/conn.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['id']);

if (empty($_SESSION['user'])) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'msg' => 'Not logged in']);
        exit;
    }
    header('location: ../index.php');
    exit;
}

$user     = $_SESSION['user'];
$id       = intval($_POST['id'] ?? $_GET['id'] ?? 0);
$class_id = intval($_POST['class_id'] ?? $_GET['class_id'] ?? 0);

if (!$id) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'msg' => 'Module ID required']);
        exit;
    }
    header('location: ../teacher/dashboard.php');
    exit;
}

$uc   = $conn->real_escape_string($user['user_code']);
$role = strtoupper($user['role'] ?? $user['user_group'] ?? '');
$isPrivileged = in_array($role, ['ADMIN', 'SUPERADMIN']);

$q = $conn->query("SELECT m.*, c.teacher_code, c.id AS class_id FROM class_modules m JOIN classes c ON m.class_id=c.id WHERE m.id=$id");

if ($q && $q->num_rows > 0) {
    $m = $q->fetch_assoc();
    $targetClassId = $m['class_id'];
    
    if ($isPrivileged || $m['teacher_code'] === $user['user_code'] || $m['uploaded_by'] === $user['user_code']) {
        $filePath = __DIR__ . '/../uploads/modules/' . $m['filename'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        $conn->query("DELETE FROM class_modules WHERE id=$id");

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'msg' => 'Material deleted successfully']);
            exit;
        }
        header('location: class_view.php?id=' . $targetClassId . '&tab=materials');
        exit;
    }
}

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'msg' => 'Unauthorized or file not found']);
    exit;
}

header('location: class_view.php?id=' . $class_id . '&tab=materials');
exit;
