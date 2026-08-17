<?php
session_start();
include '../includes/conn.php';
header('Content-Type: application/json');

if(empty($_SESSION['user']) || $_SESSION['user']['user_group'] !== 'TEACHER'){
    echo json_encode(['success' => false, 'msg' => 'Unauthorized access.']);
    exit;
}

$user = $_SESSION['user'];
$tc = $conn->real_escape_string($user['user_code']);
$action = $_POST['action'] ?? '';

// Check if class belongs to this teacher
function verifyTeacherClass($conn, $class_id, $teacher_code) {
    $cid = intval($class_id);
    $q = $conn->query("SELECT id FROM classes WHERE id=$cid AND teacher_code='$teacher_code' LIMIT 1");
    return ($q && $q->num_rows > 0);
}

if ($action === 'save') {
    $id = intval($_POST['id'] ?? 0);
    $class_id = intval($_POST['class_id'] ?? 0);
    $log_date = trim($_POST['log_date'] ?? '');
    $start_time = trim($_POST['start_time'] ?? '');
    $end_time = trim($_POST['end_time'] ?? '');
    $topic = trim($_POST['topic_covered'] ?? '');
    $activities = trim($_POST['activities'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if (!$class_id) {
        echo json_encode(['success' => false, 'msg' => 'Invalid class selected.']);
        exit;
    }

    if (!verifyTeacherClass($conn, $class_id, $tc)) {
        echo json_encode(['success' => false, 'msg' => 'You do not have permission for this class.']);
        exit;
    }

    if (!$log_date) {
        echo json_encode(['success' => false, 'msg' => 'Log date is required.']);
        exit;
    }

    if (!$start_time || !$end_time) {
        echo json_encode(['success' => false, 'msg' => 'Start and end times are required.']);
        exit;
    }

    if (strtotime($end_time) <= strtotime($start_time)) {
        echo json_encode(['success' => false, 'msg' => 'End time must be after start time.']);
        exit;
    }

    if (!$topic) {
        echo json_encode(['success' => false, 'msg' => 'Topic covered is required.']);
        exit;
    }

    // sanitize inputs
    $db_log_date = $conn->real_escape_string($log_date);
    $db_start_time = $conn->real_escape_string($start_time);
    $db_end_time = $conn->real_escape_string($end_time);
    $db_topic = $conn->real_escape_string($topic);
    $db_activities = $conn->real_escape_string($activities);
    $db_remarks = $conn->real_escape_string($remarks);

    if ($id > 0) {
        // Edit entry: verify it belongs to teacher's class
        $checkEntry = $conn->query("
            SELECT sl.id FROM subject_logbook sl 
            JOIN classes c ON sl.class_id = c.id 
            WHERE sl.id = $id AND c.teacher_code = '$tc' 
            LIMIT 1
        ");
        if (!$checkEntry || $checkEntry->num_rows === 0) {
            echo json_encode(['success' => false, 'msg' => 'Log entry not found or unauthorized.']);
            exit;
        }

        $sql = "UPDATE subject_logbook SET 
                class_id = $class_id,
                log_date = '$db_log_date',
                start_time = '$db_start_time',
                end_time = '$db_end_time',
                topic_covered = '$db_topic',
                activities = '$db_activities',
                remarks = '$db_remarks'
                WHERE id = $id";
    } else {
        // Insert entry
        $sql = "INSERT INTO subject_logbook (class_id, teacher_code, log_date, start_time, end_time, topic_covered, activities, remarks)
                VALUES ($class_id, '$tc', '$db_log_date', '$db_start_time', '$db_end_time', '$db_topic', '$db_activities', '$db_remarks')";
    }

    if ($conn->query($sql)) {
        echo json_encode(['success' => true, 'msg' => 'Log entry saved successfully.']);
    } else {
        echo json_encode(['success' => false, 'msg' => 'Database error: ' . $conn->error]);
    }
    exit;
}

if ($action === 'create_subject') {
    $class_code = trim($_POST['class_code'] ?? '');
    $subject_name = trim($_POST['subject_name'] ?? '');
    $program_code = trim($_POST['program_code'] ?? '');

    if (!$class_code) {
        echo json_encode(['success' => false, 'msg' => 'Class code is required.']);
        exit;
    }

    if (!$subject_name) {
        echo json_encode(['success' => false, 'msg' => 'Subject name is required.']);
        exit;
    }

    // Class code must be 3-10 chars, alphanumeric/hyphen
    if (!preg_match('/^[A-Za-z0-9\-]{3,10}$/', $class_code)) {
        echo json_encode(['success' => false, 'msg' => 'Class code must be 3-10 alphanumeric characters or hyphens.']);
        exit;
    }

    $db_code = strtoupper($conn->real_escape_string($class_code));
    $db_name = $conn->real_escape_string($subject_name);
    $db_prog = strtoupper($conn->real_escape_string($program_code));

    // Check if class_code is already taken
    $check_code = $conn->query("SELECT id FROM classes WHERE class_code='$db_code' LIMIT 1");
    if ($check_code && $check_code->num_rows > 0) {
        echo json_encode(['success' => false, 'msg' => "Class code '$db_code' is already taken."]);
        exit;
    }

    // Insert class
    $sql = "INSERT INTO classes (class_code, class_name, subject, program_code, teacher_code, is_subject_only) 
            VALUES ('$db_code', '$db_name', '$db_name', '$db_prog', '$tc', 1)";
    if ($conn->query($sql)) {
        $new_class_id = $conn->insert_id;
        // Auto-join teacher as member
        $conn->query("INSERT IGNORE INTO class_members (class_id, user_code) VALUES ($new_class_id, '$tc')");
        echo json_encode(['success' => true, 'msg' => 'Subject created successfully!']);
    } else {
        echo json_encode(['success' => false, 'msg' => 'Database error: ' . $conn->error]);
    }
    exit;
}

if ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);

    if (!$id) {
        echo json_encode(['success' => false, 'msg' => 'Invalid log entry ID.']);
        exit;
    }

    // Verify entry ownership
    $checkEntry = $conn->query("
        SELECT sl.id FROM subject_logbook sl 
        JOIN classes c ON sl.class_id = c.id 
        WHERE sl.id = $id AND c.teacher_code = '$tc' 
        LIMIT 1
    ");
    if (!$checkEntry || $checkEntry->num_rows === 0) {
        echo json_encode(['success' => false, 'msg' => 'Log entry not found or unauthorized.']);
        exit;
    }

    if ($conn->query("DELETE FROM subject_logbook WHERE id = $id")) {
        echo json_encode(['success' => true, 'msg' => 'Log entry deleted successfully.']);
    } else {
        echo json_encode(['success' => false, 'msg' => 'Database error: ' . $conn->error]);
    }
    exit;
}

echo json_encode(['success' => false, 'msg' => 'Invalid action.']);
?>
