<?php
header('Content-Type: application/json');
include '../includes/conn.php';

// Auto-migrate table columns if needed
safeAddColumns($conn, 'users', [
    'cp_number'   => 'varchar(20) DEFAULT NULL',
    'middle_name' => 'varchar(50) DEFAULT NULL'
]);

$username    = trim($_POST['username'] ?? '');
$password    = trim($_POST['password'] ?? '');
$first_name  = trim($_POST['first_name'] ?? '');
$middle_name = trim($_POST['middle_name'] ?? '');
$last_name   = trim($_POST['last_name'] ?? '');
$email       = trim($_POST['email'] ?? '');
$cp_number   = trim($_POST['cp_number'] ?? '');

// Validation
if(!$username || !$password || !$first_name || !$last_name || !$email){
    echo json_encode(['success' => false, 'msg' => 'Please fill in all required fields.']);
    exit;
}

if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
    echo json_encode(['success' => false, 'msg' => 'Please enter a valid email address.']);
    exit;
}

if(strlen($password) < 6){
    echo json_encode(['success' => false, 'msg' => 'Password must be at least 6 characters long.']);
    exit;
}

// Check if username already exists
$u = $conn->real_escape_string($username);
$check = $conn->query("SELECT id FROM users WHERE user_code='$u'");
if($check && $check->num_rows > 0){
    echo json_encode(['success' => false, 'msg' => 'Username already exists. Please choose another.']);
    exit;
}

// Check if email already exists
$em = $conn->real_escape_string($email);
$check_em = $conn->query("SELECT id FROM users WHERE email_address='$em'");
if($check_em && $check_em->num_rows > 0){
    echo json_encode(['success' => false, 'msg' => 'An account with this email address already exists.']);
    exit;
}

// Hash password
$password_hash = password_hash($password, PASSWORD_DEFAULT);
$ph = $conn->real_escape_string($password_hash);
$fn = $conn->real_escape_string($first_name);
$mn = $conn->real_escape_string($middle_name);
$ln = $conn->real_escape_string($last_name);
$cp = $conn->real_escape_string($cp_number);
$now = date('Y-m-d H:i:s');

// Insert new superadmin with provided details
$sql = "INSERT INTO users 
        (user_code, password_hash, first_name, middle_name, last_name, email_address, cp_number, user_group, is_active, api_cached_at)
        VALUES 
        ('$u', '$ph', '$fn', '$mn', '$ln', '$em', '$cp', 'SUPERADMIN', 1, '$now')";

if($conn->query($sql)){
    echo json_encode([
        'success' => true,
        'msg' => 'Super Admin registration successful! You can now log in with your credentials.'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'msg' => 'Registration failed due to a database error: ' . $conn->error
    ]);
}

$conn->close();
?>
