<?php
session_start();
header('Content-Type: application/json');

include '../includes/conn.php';

if(empty($_SESSION['user'])){
    echo json_encode(['success'=>false, 'msg'=>'Not authenticated']);
    exit;
}

$user = $_SESSION['user'];
$role = strtoupper($user['user_group']);

if($role !== 'SUPERADMIN'){
    echo json_encode(['success'=>false, 'msg'=>'Access denied. Only superadmin can manage users.']);
    exit;
}

$action = $_POST['action'] ?? '';
$user_code = trim($_POST['user_code'] ?? '');

// ── Create Admin Account ──────────────────────────────────────────────────
if($action === 'create_admin'){
    // Read raw first — escape AFTER validation to avoid corrupting course names
    $first_raw      = trim($_POST['first_name'] ?? '');
    $last_raw       = trim($_POST['last_name']  ?? '');
    $code_raw       = trim($_POST['user_code']  ?? '');
    $pass           = trim($_POST['password']   ?? '');
    $email_raw      = trim($_POST['email']      ?? '');
    $programs_raw   = trim($_POST['programs']   ?? '');   // comma-separated course list
    $department_raw = strtoupper(trim($_POST['department'] ?? ''));

    // Allowed departments and their valid courses
    $ALLOWED_DEPTS = [
        'IS'   => ['IS'],
        'CRIM' => ['CRIM'],
        'EDUC' => ['BSED-FILIPINO','BSED-MATHEMATICS','BSED-SOCIAL STUDIES','BPED','BEED'],
        'ART'  => ['BSOA','AB HISTORY','AB ENGLISH'],
    ];

    if(!$first_raw || !$last_raw || !$code_raw || !$pass){
        echo json_encode(['success'=>false,'msg'=>'Required fields missing']); exit;
    }
    if(strlen($pass) < 6){
        echo json_encode(['success'=>false,'msg'=>'Password must be at least 6 characters']); exit;
    }
    if(!$department_raw || !array_key_exists($department_raw, $ALLOWED_DEPTS)){
        echo json_encode(['success'=>false,'msg'=>'Invalid department selected']); exit;
    }

    // Ensure department column exists (auto-migrate)
safeAddColumns($conn, 'users', ['department' => 'varchar(20) DEFAULT NULL']);

    // Enforce: only 1 admin per department
    $deptEsc   = $conn->real_escape_string($department_raw);
    $deptCheck = $conn->query("SELECT id FROM users WHERE user_group='ADMIN' AND department='$deptEsc'");
    if($deptCheck->num_rows > 0){
        echo json_encode(['success'=>false,'msg'=>"The $department_raw department already has an admin account."]); exit;
    }

    // Enforce: max 4 admin accounts total
    $totalAdmins = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE user_group='ADMIN'");
    $adminCount  = $totalAdmins->fetch_assoc()['cnt'];
    if($adminCount >= 4){
        echo json_encode(['success'=>false,'msg'=>'Maximum of 4 admin accounts has been reached.']); exit;
    }

    // Validate submitted courses belong to the chosen department
    // Compare uppercase to uppercase so spacing/case differences don't break it
    $allowedUpper     = array_map('strtoupper', $ALLOWED_DEPTS[$department_raw]);
    $submittedCourses = array_filter(array_map('trim', explode(',', $programs_raw)));
    $validCourses     = [];
    foreach($submittedCourses as $c){
        $cu = strtoupper($c);
        // Find the canonical name from the allowed list
        $idx = array_search($cu, $allowedUpper);
        if($idx !== false){
            $validCourses[] = $ALLOWED_DEPTS[$department_raw][$idx]; // keep original casing
        }
    }
    if(empty($validCourses)){
        echo json_encode(['success'=>false,'msg'=>'No valid courses selected for this department.']); exit;
    }

    // Check duplicate username
    $code_esc = $conn->real_escape_string($code_raw);
    $chk = $conn->query("SELECT id FROM users WHERE user_code='$code_esc'");
    if($chk->num_rows > 0){
        echo json_encode(['success'=>false,'msg'=>'Username already exists']); exit;
    }

    // Escape everything for INSERT
    $first         = $conn->real_escape_string($first_raw);
    $last          = $conn->real_escape_string($last_raw);
    $email         = $conn->real_escape_string($email_raw);
    $cleanPrograms = $conn->real_escape_string(implode(', ', $validCourses));
    $hash          = $conn->real_escape_string(password_hash($pass, PASSWORD_DEFAULT));

    $ok = $conn->query("INSERT INTO users
        (user_code, password_hash, first_name, last_name, email_address, department, program_description, user_group, is_active)
        VALUES
        ('$code_esc','$hash','$first','$last','$email','$deptEsc','$cleanPrograms','ADMIN',1)");

    if($ok){
        echo json_encode(['success'=>true,'msg'=>"Admin account for the $department_raw department created successfully!"]);
    } else {
        echo json_encode(['success'=>false,'msg'=>'Database error: '.$conn->error]);
    }
    exit;
}

if(!$action || !$user_code){
    echo json_encode(['success'=>false, 'msg'=>'Invalid parameters']);
    exit;
}

$uc = $conn->real_escape_string($user_code);

if($action === 'promote'){
    // Promote teacher to admin
    $sql = "UPDATE users SET user_group='ADMIN' WHERE user_code='$uc' AND user_group='TEACHER'";
    
    if($conn->query($sql)){
        if($conn->affected_rows > 0){
            echo json_encode(['success'=>true, 'msg'=>'User promoted to ADMIN successfully!']);
        } else {
            echo json_encode(['success'=>false, 'msg'=>'User not found or already an admin']);
        }
    } else {
        echo json_encode(['success'=>false, 'msg'=>'Database error: ' . $conn->error]);
    }
    
} elseif($action === 'demote'){
    // Demote admin to teacher
    $sql = "UPDATE users SET user_group='TEACHER' WHERE user_code='$uc' AND user_group='ADMIN'";
    
    if($conn->query($sql)){
        if($conn->affected_rows > 0){
            echo json_encode(['success'=>true, 'msg'=>'User demoted to TEACHER successfully!']);
        } else {
            echo json_encode(['success'=>false, 'msg'=>'User not found or not an admin']);
        }
    } else {
        echo json_encode(['success'=>false, 'msg'=>'Database error: ' . $conn->error]);
    }
    
} else {
    echo json_encode(['success'=>false, 'msg'=>'Invalid action']);
}

$conn->close();
?>
